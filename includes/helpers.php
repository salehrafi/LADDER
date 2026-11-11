<?php
function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// ---- Request, session and errors -------------------------------------------------

/** Is this request served over HTTPS (directly or behind a TLS-terminating proxy)? */
function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

/**
 * Start the session with hardened cookie settings: not readable by scripts,
 * not sent on cross-site POSTs, HTTPS-only when the site uses HTTPS, and
 * unknown session ids are rejected instead of adopted (strict mode).
 */
function start_app_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => is_https(),
    ]);
    session_start();

    // Log out after a long period without any request. Generous by default
    // (SESSION_IDLE_MINUTES, 240) so a long exam is never cut off; the quiz
    // page's autosave also counts as activity.
    $idle = max(5, (int) env_value('SESSION_IDLE_MINUTES', 240)) * 60;
    if (!empty($_SESSION['logged_in']) && isset($_SESSION['last_seen']) && time() - (int) $_SESSION['last_seen'] > $idle) {
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['flash'][] = ['type' => 'info', 'message' => 'You were signed out after a period of inactivity. Please log in again.'];
    }
    $_SESSION['last_seen'] = time();
}

/**
 * Headers that apply to every page: no framing by other sites (clickjacking,
 * which matters for the exam page), no MIME sniffing, no full-URL referrers,
 * and no access to camera, microphone or location.
 */
function send_security_headers(): void
{
    if (headers_sent() || PHP_SAPI === 'cli') {
        return;
    }
    header('X-Frame-Options: DENY');
    header("Content-Security-Policy: frame-ancestors 'none'");
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
}

/** The client address for throttling (REMOTE_ADDR only: forwarded headers can be forged). */
function client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
}

// ---- Throttling (login, registration, password reset) ------------------------------

// action => [max events, window in seconds]
const THROTTLE_RULES = [
    'login_email' => [5, 900],        // failed logins per account per 15 minutes
    'login_ip' => [30, 900],          // failed logins per address per 15 minutes
    'register_ip' => [10, 3600],      // sign-ups per address per hour
    'otp_request_email' => [3, 900],  // reset codes per account per 15 minutes
    'otp_request_ip' => [10, 3600],   // reset codes per address per hour
    'verify_email' => [3, 900],       // verification emails per account per 15 minutes
    'verify_ip' => [10, 3600],        // verification resends per address per hour
];

function throttle_subject(string $subject): string
{
    return mb_substr(mb_strtolower(trim($subject)), 0, 190);
}

/** Has $subject reached the limit for $action within its window? */
function throttle_blocked(mysqli $conn, string $action, string $subject): bool
{
    [$max, $window] = THROTTLE_RULES[$action];
    $subject = throttle_subject($subject);
    $since = date('Y-m-d H:i:s', time() - $window);
    $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM auth_throttle WHERE action = ? AND subject = ? AND created_at > ?");
    $stmt->bind_param("sss", $action, $subject, $since);
    $stmt->execute();
    $count = (int) $stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();
    return $count >= $max;
}

/** Count one event (a failed login, a sign-up, a code request). */
function throttle_record(mysqli $conn, string $action, string $subject): void
{
    $subject = throttle_subject($subject);
    $now = date('Y-m-d H:i:s');
    $stmt = $conn->prepare("INSERT INTO auth_throttle (action, subject, created_at) VALUES (?, ?, ?)");
    $stmt->bind_param("sss", $action, $subject, $now);
    $stmt->execute();
    $stmt->close();
    // Occasionally drop rows older than every window.
    if (random_int(1, 50) === 1) {
        $conn->query("DELETE FROM auth_throttle WHERE created_at < (NOW() - INTERVAL 1 DAY)");
    }
}

/** Forget the events for a subject (e.g. failed logins after a successful one). */
function throttle_clear(mysqli $conn, string $action, string $subject): void
{
    $subject = throttle_subject($subject);
    $stmt = $conn->prepare("DELETE FROM auth_throttle WHERE action = ? AND subject = ?");
    $stmt->bind_param("ss", $action, $subject);
    $stmt->execute();
    $stmt->close();
}

// ---- Password reset codes --------------------------------------------------------

const OTP_LIFETIME_MINUTES = 10;
// Wrong codes allowed per issued code; then it is void (counted in the
// database, so starting a new browser session does not reset the count).
const OTP_MAX_ATTEMPTS = 5;

/**
 * Issue a new 6-digit reset code for an active account. Only a hash is
 * stored. Returns the code to email, or null if there is no such account.
 */
function issue_password_reset_code(mysqli $conn, string $email): ?string
{
    $code = (string) random_int(100000, 999999);
    $hash = password_hash($code, PASSWORD_DEFAULT);
    $expires = date('Y-m-d H:i:s', time() + OTP_LIFETIME_MINUTES * 60);
    $stmt = $conn->prepare("UPDATE reg_info SET otp = ?, exp_time = ?, otp_attempts = 0 WHERE email = ? AND pass <> ''");
    $stmt->bind_param("sss", $hash, $expires, $email);
    $stmt->execute();
    $issued = $stmt->affected_rows === 1;
    $stmt->close();
    return $issued ? $code : null;
}

/**
 * Check a reset code. A correct, unexpired code is used up; a wrong one
 * counts towards OTP_MAX_ATTEMPTS, after which the code is void.
 */
function verify_password_reset_code(mysqli $conn, string $email, string $code): bool
{
    $code = preg_replace('/\D/', '', $code);
    $stmt = $conn->prepare("SELECT otp, exp_time, otp_attempts FROM reg_info WHERE email = ? AND pass <> ''");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row || $row['otp'] === null) {
        return false;
    }

    $valid = (int) $row['otp_attempts'] < OTP_MAX_ATTEMPTS
        && time() < strtotime((string) $row['exp_time'])
        && strlen($code) === 6
        && password_verify($code, $row['otp']);

    if ($valid) {
        // The code reached this inbox, which proves the address as well as a
        // verification link would.
        $stmt = $conn->prepare(
            "UPDATE reg_info SET otp = NULL, exp_time = NULL, otp_attempts = 0,
                email_verified = 1, verify_token_hash = NULL, verify_expires = NULL WHERE email = ?"
        );
        $stmt->bind_param("s", $email);
    } else {
        // MySQL applies SET assignments left to right, so otp_attempts in the
        // IF is already the incremented value.
        $max = OTP_MAX_ATTEMPTS;
        $stmt = $conn->prepare(
            "UPDATE reg_info SET otp_attempts = otp_attempts + 1, otp = IF(otp_attempts >= ?, NULL, otp) WHERE email = ?"
        );
        $stmt->bind_param("is", $max, $email);
    }
    $stmt->execute();
    $stmt->close();
    return $valid;
}

// ---- Email verification (public sign-up) ----------------------------------------

const VERIFY_LINK_LIFETIME_HOURS = 24;

/**
 * Give an unverified account a fresh verification link. Any earlier link stops
 * working. Only the SHA-256 of the token is stored.
 *
 * @return string|null the token to email, or null if the account is not waiting for verification
 */
function issue_email_verification(mysqli $conn, string $email): ?string
{
    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);
    $expires = date('Y-m-d H:i:s', time() + VERIFY_LINK_LIFETIME_HOURS * 3600);
    $stmt = $conn->prepare(
        "UPDATE reg_info SET verify_token_hash = ?, verify_expires = ? WHERE email = ? AND email_verified = 0"
    );
    $stmt->bind_param("sss", $hash, $expires, $email);
    $stmt->execute();
    $issued = $stmt->affected_rows === 1;
    $stmt->close();
    return $issued ? $token : null;
}

/**
 * Use a verification token. A valid, unexpired token activates its account and
 * is used up. Returns the account email, or null if the link is invalid or old.
 */
function verify_email_token(mysqli $conn, string $token): ?string
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    $hash = hash('sha256', $token);
    $stmt = $conn->prepare(
        "SELECT email FROM reg_info WHERE verify_token_hash = ? AND email_verified = 0 AND verify_expires > ? LIMIT 1"
    );
    $now = date('Y-m-d H:i:s');
    $stmt->bind_param("ss", $hash, $now);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        return null;
    }
    $stmt = $conn->prepare(
        "UPDATE reg_info SET email_verified = 1, verify_token_hash = NULL, verify_expires = NULL
         WHERE verify_token_hash = ? AND email_verified = 0"
    );
    $stmt->bind_param("s", $hash);
    $stmt->execute();
    $done = $stmt->affected_rows === 1;
    $stmt->close();
    return $done ? (string) $row['email'] : null;
}

/** Absolute URL of a page in auth/, from APP_URL (preferred) or this request. */
function auth_page_url(string $page): string
{
    $base = rtrim((string) env_value('APP_URL', ''), '/');
    if ($base === '') {
        // Fallback for local setups. Set APP_URL in production so links in
        // emails never depend on the request's Host header.
        $dir = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
        $base = (is_https() ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . preg_replace('#/auth$#', '', $dir);
    }
    return $base . '/auth/' . $page;
}

/** Email the verification link. Returns false if it could not be sent (logged). */
function send_verification_email(string $email, string $name, string $token): bool
{
    // Reserved test domains can never receive mail; don't hand them to SMTP.
    if (preg_match('/\.(local|test|invalid|example|localhost)$/i', $email)) {
        error_log("Verification email skipped for reserved test domain.");
        return true;
    }
    $link = auth_page_url('verify_email.php?token=' . rawurlencode($token));
    $hours = VERIFY_LINK_LIFETIME_HOURS;
    $html = '<div style="font-family:Arial,sans-serif;line-height:1.6;color:#0B1220;max-width:520px">'
        . '<p style="font-size:18px;font-weight:bold;letter-spacing:2px;color:#4F46E5">LADDER</p>'
        . '<p>Hi ' . e($name) . ',</p>'
        . '<p>Confirm your email address to activate your LADDER account.</p>'
        . '<p><a href="' . e($link) . '" style="display:inline-block;padding:12px 22px;background:#4F46E5;color:#fff;text-decoration:none;border-radius:8px;font-weight:bold">Verify my email</a></p>'
        . '<p style="color:#475569;font-size:13px">Or paste this link into your browser:<br>' . e($link) . '</p>'
        . '<p style="color:#475569;font-size:13px">The link expires in ' . $hours . ' hours. If you did not sign up, you can ignore this email.</p>'
        . '</div>';
    $text = "Hi $name,\n\nConfirm your email address to activate your LADDER account:\n$link\n\n"
        . "The link expires in $hours hours. If you did not sign up, you can ignore this email.";
    return send_app_mail($email, 'Verify your LADDER account', $html, $text);
}

/**
 * A unique Teacher ID such as TCH-7F3A90C2. Uniqueness is checked here and
 * enforced by the UNIQUE key on reg_info.id (the caller retries on a clash).
 */
function generate_teacher_id(mysqli $conn): string
{
    $check = $conn->prepare("SELECT 1 FROM reg_info WHERE id = ? LIMIT 1");
    for ($i = 0; $i < 10; $i++) {
        $id = 'TCH-' . strtoupper(bin2hex(random_bytes(4)));
        $check->bind_param("s", $id);
        $check->execute();
        if (!$check->get_result()->fetch_row()) {
            $check->close();
            return $id;
        }
    }
    $check->close();
    throw new RuntimeException('Could not generate a unique Teacher ID.');
}

/** Student IDs: 1-50 characters, letters, digits and . _ / - (no spaces). Returns an error or null. */
function student_id_error(string $id): ?string
{
    if ($id === '') {
        return 'Student ID is required.';
    }
    if (mb_strlen($id) > 50 || !preg_match('#^[A-Za-z0-9][A-Za-z0-9._/-]*$#', $id)) {
        return 'Student ID must be up to 50 characters: letters, digits, and . _ / - only.';
    }
    if (stripos($id, 'TCH-') === 0) {
        return 'Student ID cannot start with TCH- (reserved for teachers).';
    }
    return null;
}

// ---- Account sessions ----------------------------------------------------------

/**
 * End every other session of an account (after a password change or reset).
 * If $keep_current, this session stays signed in with a fresh id.
 */
function bump_auth_version(mysqli $conn, string $email, bool $keep_current = false): void
{
    $stmt = $conn->prepare("UPDATE reg_info SET auth_version = auth_version + 1 WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $stmt->close();
    if ($keep_current && session_status() === PHP_SESSION_ACTIVE) {
        $stmt = $conn->prepare("SELECT auth_version FROM reg_info WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $_SESSION['auth_version'] = (int) ($stmt->get_result()->fetch_assoc()['auth_version'] ?? 0);
        $stmt->close();
        session_regenerate_id(true);
    }
}

/**
 * Is the logged-in session still valid? The account must still exist with
 * the same role, and its password must not have changed since this session
 * logged in (auth_version).
 */
function session_account_valid(): bool
{
    global $conn;
    if (!($conn instanceof mysqli)) {
        return true;
    }
    $email = (string) ($_SESSION['user_email'] ?? '');
    $stmt = $conn->prepare("SELECT role, auth_version FROM reg_info WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row || $row['role'] !== ($_SESSION['role'] ?? '')) {
        return false;
    }
    if (!isset($_SESSION['auth_version'])) {
        // Signed in before this check existed: adopt the current version.
        $_SESSION['auth_version'] = (int) $row['auth_version'];
    }
    return (int) $row['auth_version'] === (int) $_SESSION['auth_version'];
}

/** Sign the user out of this session (keeps the session itself for flash messages). */
function logout_session(): void
{
    $_SESSION = [];
    session_regenerate_id(true);
}

/** Does the client expect a JSON response (fetch/sendBeacon endpoints)? */
function wants_json(): bool
{
    return stripos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== false
        || stripos((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false;
}

/**
 * Last-resort handler: log the real error, show the visitor a short message
 * (or a JSON error to scripts) instead of a blank page. Details are shown
 * only when APP_DEBUG=true.
 */
function install_error_handler(): void
{
    set_exception_handler(function (Throwable $e): void {
        error_log('Uncaught ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        $debug = env_value('APP_DEBUG', 'false') === 'true';

        if (!headers_sent()) {
            http_response_code(500);
        }
        if (wants_json()) {
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode(['ok' => false, 'error' => 'server_error'] + ($debug ? ['detail' => $e->getMessage()] : []));
            return;
        }
        echo '<p style="font-family:system-ui,sans-serif;padding:24px">Something went wrong. Please go back and try again.</p>';
        if ($debug) {
            echo '<pre style="padding:0 24px;white-space:pre-wrap">' . e((string) $e) . '</pre>';
        }
    });
}

/** Send a JSON response and stop. */
function json_response(int $code, array $payload): void
{
    http_response_code($code);
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($payload);
    exit;
}

/**
 * Guard a JSON endpoint: the user must be logged in with $role, the request
 * must be a POST with a JSON object body carrying a valid CSRF token.
 *
 * @return array the decoded request body
 */
function require_role_json(string $role): array
{
    if (!isset($_SESSION['logged_in']) || ($_SESSION['role'] ?? '') !== $role || empty($_SESSION['user_email'])) {
        json_response(401, ['ok' => false, 'error' => 'login_required']);
    }
    if (!session_account_valid()) {
        logout_session();
        json_response(401, ['ok' => false, 'error' => 'login_required']);
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        json_response(405, ['ok' => false, 'error' => 'method_not_allowed']);
    }
    $body = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($body)) {
        json_response(400, ['ok' => false, 'error' => 'bad_json']);
    }
    if (!csrf_valid($body['csrf_token'] ?? null)) {
        json_response(403, ['ok' => false, 'error' => 'bad_token']);
    }
    return $body;
}

// ---- Flash messages and redirects ---------------------------------------------

/** Queue a message for the next page the user sees (type: success, error, info, warning). */
function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

/** @return array<int,array{type:string,message:string}> queued messages, removed from the session */
function take_flashes(): array
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return is_array($messages) ? $messages : [];
}

/** Redirect and stop (use after a successful POST so a refresh cannot repeat it). */
function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

/**
 * Guard a page for one role. Also enforces CSRF on POST, so every
 * role-protected page is covered without repeating the check.
 */
function require_role(string $role, string $login_redirect): void
{
    if (!isset($_SESSION['logged_in']) || ($_SESSION['role'] ?? '') !== $role || empty($_SESSION['user_email'])) {
        header("Location: $login_redirect");
        exit();
    }
    if (!session_account_valid()) {
        logout_session();
        flash('info', 'Your password was changed, so you were signed out. Please log in again.');
        header("Location: $login_redirect");
        exit();
    }
    require_password_set($login_redirect);
    verify_csrf();
}

/**
 * Users logging in with a teacher-set default password must choose their own
 * before using any page. $login_redirect is the page's relative path to
 * auth/login.php; first_login.php sits next to it.
 */
function require_password_set(string $login_redirect): void
{
    if (!empty($_SESSION['must_change_password'])) {
        header('Location: ' . preg_replace('/login\.php$/', 'first_login.php', $login_redirect));
        exit();
    }
}

/** Home page for each role, relative to the app root. */
function dashboard_for_role(string $role): string
{
    $map = [
        'teacher' => 'dashboards/teacherDashboard.php',
        'student' => 'dashboards/studentDashboard.php',
    ];
    return $map[$role] ?? 'auth/login.php';
}

const PASSWORD_MIN_LENGTH = 6;
const PASSWORD_MAX_LENGTH = 64; // bcrypt reads at most 72 bytes
// Hash of a random string nobody knows: checked when an email is unknown so
// a failed login takes the same time either way.
const DUMMY_PASSWORD_HASH = '$2y$10$qjhpwdmxU2yA5ICHZkS8Fec6QnsahGvWSAG0nYpOJciXFTLaIvhK2';

/** Shared password rule. Returns an error or null. */
function password_rule_error(string $password): ?string
{
    $len = strlen($password);
    return ($len < PASSWORD_MIN_LENGTH || $len > PASSWORD_MAX_LENGTH)
        ? 'Password must be ' . PASSWORD_MIN_LENGTH . '-' . PASSWORD_MAX_LENGTH . ' characters.'
        : null;
}

/** Per-session token that every state-changing form must send back. */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Hidden input carrying the CSRF token; echo it inside each POST form. */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function csrf_valid(?string $token): bool
{
    return is_string($token) && !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Reject a POST without a valid token. GET requests pass through; they must
 * never change data.
 */
function verify_csrf(?string $token = null): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return;
    }
    if (!csrf_valid($token ?? ($_POST['csrf_token'] ?? null))) {
        http_response_code(403);
        exit('Your session expired or the form was out of date. Go back, reload the page and try again.');
    }
}

/** Mark a user as logged in. Regenerates the session id to stop session fixation. */
function login_user(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['name'] = $user['full_name'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['logged_in'] = true;
    $_SESSION['must_change_password'] = !empty($user['must_change_password']);
    $_SESSION['auth_version'] = (int) ($user['auth_version'] ?? 0);
    $_SESSION['last_seen'] = time();
    unset($_SESSION['csrf_token']);
}

/**
 * Neutralise spreadsheet formulas in CSV cells (CSV injection): a value that
 * starts with = + - @ or a control char is prefixed with a quote.
 */
function csv_safe($value): string
{
    $value = (string) $value;
    return ($value !== '' && strpbrk($value[0], "=+-@\t\r") !== false) ? "'" . $value : $value;
}
/**
 * Stream rows as a CSV download and stop. Every cell goes through csv_safe().
 * RFC 4180 quoting only (no backslash escape character).
 */
function send_csv(string $filename, array $header, iterable $rows): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]+/', '-', $filename) . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel reads accents and Bangla correctly
    fputcsv($out, $header, ',', '"', '');
    foreach ($rows as $row) {
        fputcsv($out, array_map('csv_safe', $row), ',', '"', '');
    }
    fclose($out);
    exit;
}

function current_user_email(): ?string
{
    return $_SESSION['user_email'] ?? null;
}

function quiz_availability(array $quiz): array
{
    if ($quiz['status'] === 'draft') {
        return ['open' => false, 'label' => 'Draft (not published)'];
    }
    if ($quiz['status'] === 'closed') {
        return ['open' => false, 'label' => 'Closed'];
    }

    $now = time();

    if (!empty($quiz['start_time']) && $now < strtotime($quiz['start_time'])) {
        return ['open' => false, 'label' => 'Opens ' . date('M j, Y g:i A', strtotime($quiz['start_time']))];
    }

    if (!empty($quiz['end_time']) && $now > strtotime($quiz['end_time'])) {
        return ['open' => false, 'label' => 'Closed (deadline passed)'];
    }

    if (!empty($quiz['end_time'])) {
        return ['open' => true, 'label' => 'Open until ' . date('M j, Y g:i A', strtotime($quiz['end_time']))];
    }

    return ['open' => true, 'label' => 'Open'];
}

function attempts_label(int $max_attempts): string
{
    return $max_attempts === 0 ? 'Unlimited' : (string) $max_attempts;
}

function fmt_score($value): string
{
    if ($value === null) {
        return '-';
    }
    $num = (float) $value;
    return (floor($num) == $num) ? (string) (int) $num : rtrim(rtrim(number_format($num, 2), '0'), '.');
}
