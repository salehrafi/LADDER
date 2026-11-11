<?php
/**
 * Sign in and sign up on one page (tabs). auth/registration.php serves the
 * same page with the sign-up tab open, so old links and forms keep working.
 *
 * POST action:
 *   login    - email + password; the account must have a verified email.
 *   register - public sign-up. Teachers get a generated Teacher ID; students
 *              must give their Student ID. The account stays inactive until
 *              the emailed verification link is opened.
 *   resend   - send a new verification link (throttled, same reply either way).
 */
require_once __DIR__ . '/../includes/bootstrap.php';

if (isset($_SESSION['logged_in'], $_SESSION['role'])) {
    // A default password must be replaced before anything else.
    header('Location: ' . (!empty($_SESSION['must_change_password']) ? 'first_login.php' : '../' . dashboard_for_role($_SESSION['role'])));
    exit();
}

$mode = $auth_default_mode ?? ((($_GET['mode'] ?? '') === 'signup') ? 'signup' : 'signin');

$signin_error = '';
$signup_error = '';
$notice = '';
$notice_type = 'info';
$unverified_email = '';      // right password, but the email is not verified yet
$pending = null;             // ['email', 'message', 'type']: shows "check your inbox"
$old = ['name' => '', 'email' => '', 'id' => '', 'role' => 'student', 'signin_email' => ''];
$already_exists = "An account with that email already exists. Sign in, or reset your password if you forgot it.";

foreach (take_flashes() as $f) {
    $notice = $f['message'];
    $notice_type = $f['type'];
}

if ($_SERVER['REQUEST_METHOD'] === "POST") {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? ($mode === 'signup' ? 'register' : 'login'));

    if ($action === 'login') {
        $mode = 'signin';
        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $old['signin_email'] = $email;
        $ip = client_ip();

        // Too many failures for this account or from this address: refuse without
        // checking the password, so guessing cannot continue.
        if (throttle_blocked($conn, 'login_email', $email) || throttle_blocked($conn, 'login_ip', $ip)) {
            $signin_error = "Too many failed sign-in attempts. Wait 15 minutes and try again, or reset your password.";
        } else {
            $stmt = $conn->prepare("SELECT full_name, email, pass, role, must_change_password, auth_version, email_verified FROM reg_info WHERE email = ? LIMIT 1");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            // Always run one hash check so response time does not reveal whether
            // the email exists. (A disabled account has an empty hash.)
            $has_hash = $user && (string) $user['pass'] !== '';
            $ok = password_verify($password, $has_hash ? $user['pass'] : DUMMY_PASSWORD_HASH) && $has_hash;

            if ($ok && empty($user['email_verified'])) {
                // Said only after the right password, so it tells a guesser nothing.
                throttle_clear($conn, 'login_email', $email);
                $signin_error = "Please verify your email before signing in. Open the link we sent you, or send a new one.";
                $unverified_email = (string) $user['email'];
            } elseif ($ok) {
                throttle_clear($conn, 'login_email', $email);
                login_user($user);
                header('Location: ' . (!empty($user['must_change_password']) ? 'first_login.php' : '../' . dashboard_for_role($user['role'])));
                exit();
            } else {
                throttle_record($conn, 'login_email', $email);
                throttle_record($conn, 'login_ip', $ip);
                $signin_error = "Invalid email or password.";
            }
        }
    } elseif ($action === 'register') {
        $mode = 'signup';
        $role = trim((string) ($_POST['user_type'] ?? ''));
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');
        $student_id = trim((string) ($_POST['id'] ?? ''));
        $old = [
            'name' => $name, 'email' => $email, 'id' => $student_id, 'signin_email' => '',
            'role' => in_array($role, ['teacher', 'student'], true) ? $role : 'student',
        ];

        if (throttle_blocked($conn, 'register_ip', client_ip())) {
            $signup_error = "Too many sign-ups from this network. Please try again later.";
        } elseif (!in_array($role, ['teacher', 'student'], true)) {
            $signup_error = "Please choose whether you are a student or a teacher.";
        } elseif ($name === '' || mb_strlen($name) > 50) {
            $signup_error = "Please enter your name (up to 50 characters).";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100) {
            $signup_error = "Please enter a valid email address.";
        } elseif ($err = password_rule_error($password)) {
            $signup_error = $err;
        } elseif ($role === 'student' && ($err = student_id_error($student_id))) {
            $signup_error = $err;
        } else {
            throttle_record($conn, 'register_ip', client_ip());

            $stmt = $conn->prepare("SELECT email_verified, verify_expires FROM reg_info WHERE email = ? LIMIT 1");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $existing = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $existing_pending = $existing && empty($existing['email_verified'])
                && $existing['verify_expires'] !== null && strtotime((string) $existing['verify_expires']) > time();

            if ($existing && !empty($existing['email_verified'])) {
                $signup_error = $already_exists;
            } elseif ($existing_pending) {
                // The same sign-up again while its link is still valid: the first
                // details are kept and a fresh link is sent (throttled).
                if (throttle_blocked($conn, 'verify_email', $email)) {
                    $pending = ['email' => $email, 'type' => 'info', 'message' => "This email is already waiting for verification. We sent a link a few minutes ago, so check your inbox and spam folder."];
                } else {
                    throttle_record($conn, 'verify_email', $email);
                    $token = issue_email_verification($conn, $email);
                    $sent = $token !== null && send_verification_email($email, $name, $token);
                    $pending = $sent
                        ? ['email' => $email, 'type' => 'info', 'message' => "This email is already waiting for verification, so we sent a new link."]
                        : ['email' => $email, 'type' => 'error', 'message' => "We couldn't send the verification email right now. Try \"Resend link\" in a few minutes."];
                }
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                if ($role === 'student') {
                    // A Student ID held by a sign-up that was never verified, and
                    // whose link has expired, is released.
                    $stmt = $conn->prepare(
                        "UPDATE reg_info SET id = NULL WHERE id = ? AND email <> ? AND email_verified = 0
                         AND (verify_expires IS NULL OR verify_expires < NOW())"
                    );
                    $stmt->bind_param("ss", $student_id, $email);
                    $stmt->execute();
                    $stmt->close();
                }

                $saved = false;
                for ($try = 0; $try < 3 && !$saved && $signup_error === ''; $try++) {
                    $id = $role === 'teacher' ? generate_teacher_id($conn) : $student_id;
                    $stmt = $existing
                        // An expired, never-verified sign-up with this email starts over.
                        ? $conn->prepare("UPDATE reg_info SET id = ?, full_name = ?, pass = ?, role = ?, must_change_password = 0 WHERE email = ? AND email_verified = 0")
                        : $conn->prepare("INSERT INTO reg_info (id, full_name, pass, role, email, email_verified) VALUES (?, ?, ?, ?, ?, 0)");
                    $stmt->bind_param("sssss", $id, $name, $hash, $role, $email);
                    try {
                        $stmt->execute();
                        $saved = true;
                    } catch (mysqli_sql_exception $e) {
                        if ($e->getCode() !== 1062) {
                            error_log("Registration failed: " . $e->getMessage());
                            $signup_error = "Something went wrong. Please try again.";
                        } elseif (stripos($e->getMessage(), "for key 'email'") !== false
                            || stripos($e->getMessage(), "reg_info.email'") !== false) {
                            // Another sign-up for this email won the race.
                            $signup_error = $already_exists;
                        } elseif ($role === 'student') {
                            $signup_error = "That Student ID is already registered to another account.";
                        }
                        // Teacher: the generated ID clashed (very unlikely), so try another.
                    }
                    $stmt->close();
                }

                if ($saved) {
                    throttle_record($conn, 'verify_email', $email);
                    $token = issue_email_verification($conn, $email);
                    $sent = $token !== null && send_verification_email($email, $name, $token);
                    $pending = $sent
                        ? ['email' => $email, 'type' => 'success', 'message' => "Account created. We sent a verification link to your email. Open it to activate your account."]
                        : ['email' => $email, 'type' => 'error', 'message' => "Your account was created, but we couldn't send the verification email right now. Try \"Resend link\" in a few minutes."];
                } elseif ($signup_error === '') {
                    $signup_error = "Something went wrong. Please try again.";
                }
            }
        }
    } elseif ($action === 'resend') {
        $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
        $ip = client_ip();
        if (filter_var($email, FILTER_VALIDATE_EMAIL)
            && !throttle_blocked($conn, 'verify_email', $email) && !throttle_blocked($conn, 'verify_ip', $ip)) {
            throttle_record($conn, 'verify_email', $email);
            throttle_record($conn, 'verify_ip', $ip);
            $stmt = $conn->prepare("SELECT full_name FROM reg_info WHERE email = ? AND email_verified = 0 AND pass <> '' LIMIT 1");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row && ($token = issue_email_verification($conn, $email)) !== null) {
                send_verification_email($email, (string) $row['full_name'], $token);
            }
        }
        // The same reply whatever happened, so this cannot be used to probe accounts.
        $pending = ['email' => $email, 'type' => 'info', 'message' => "If that account is waiting for verification, a new link is on its way. It can take a minute to arrive."];
    }
}

$is_signup = $mode === 'signup';
$pw_hint = 'At least ' . PASSWORD_MIN_LENGTH . ' characters';
$eye = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>';
$ladder = '<svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M8 3v18M16 3v18M8 17h8M8 12h8M8 7h8"/></svg>';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0B1220">
    <title><?php echo $pending ? 'Check your inbox' : ($is_signup ? 'Create account' : 'Sign in'); ?> · LADDER</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="auth.css?v=5">
</head>

<body>
    <div class="auth">
        <aside class="auth-side" aria-hidden="true">
            <a class="brand" href="../index.php" tabindex="-1"><span class="mark"><?php echo $ladder; ?></span>LADDER</a>
            <div class="side-copy">
                <p class="kicker">Assessment platform</p>
                <h2>Climb Higher.<br><span>Achieve More.</span></h2>
                <p>Every quiz is a step forward. Pick up right where you left off.</p>
            </div>
            <div class="rungs">
                <div class="rung" style="--i:3"><b>Final Exam</b><i style="--w:94%"></i><em>94%</em></div>
                <div class="rung" style="--i:2"><b>Algorithms</b><i style="--w:81%"></i><em>81%</em></div>
                <div class="rung" style="--i:1"><b>Database</b><i style="--w:66%"></i><em>66%</em></div>
                <div class="rung" style="--i:0"><b>English</b><i style="--w:48%"></i><em>48%</em></div>
            </div>
            <p class="side-foot">&copy; <?php echo date('Y'); ?> LADDER</p>
        </aside>

        <main class="auth-main">
            <a class="brand brand-mobile" href="../index.php"><span class="mark"><?php echo $ladder; ?></span>LADDER</a>

            <div class="card">
                <?php if ($pending): ?>
                    <section class="pending" aria-live="polite">
                        <div class="pending-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/></svg>
                        </div>
                        <h1>Check your inbox</h1>
                        <p class="alert alert-<?php echo e($pending['type']); ?>"><?php echo e($pending['message']); ?></p>
                        <?php if ($pending['email'] !== ''): ?>
                            <p class="muted">Sent to <strong><?php echo e($pending['email']); ?></strong>. The link works for <?php echo VERIFY_LINK_LIFETIME_HOURS; ?> hours.</p>
                        <?php endif; ?>
                        <div class="pending-actions">
                            <a class="btn btn-primary" href="login.php">Back to sign in</a>
                            <form method="POST" action="login.php">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="resend">
                                <input type="hidden" name="email" value="<?php echo e($pending['email']); ?>">
                                <button type="submit" class="btn btn-ghost">Resend link</button>
                            </form>
                        </div>
                    </section>
                <?php else: ?>
                    <header class="card-head">
                        <h1 id="auth-title" data-signin="Welcome back" data-signup="Create your account"><?php echo $is_signup ? 'Create your account' : 'Welcome back'; ?></h1> <br>
                    </header>

                    <div class="tabs" role="tablist" aria-label="Account" data-mode="<?php echo $is_signup ? 'signup' : 'signin'; ?>">
                        <a class="tab<?php echo $is_signup ? '' : ' is-active'; ?>" id="tab-signin" role="tab" href="login.php" aria-controls="panel-signin" aria-selected="<?php echo $is_signup ? 'false' : 'true'; ?>" data-mode="signin">Sign in</a>
                        <a class="tab<?php echo $is_signup ? ' is-active' : ''; ?>" id="tab-signup" role="tab" href="login.php?mode=signup" aria-controls="panel-signup" aria-selected="<?php echo $is_signup ? 'true' : 'false'; ?>" data-mode="signup">Sign up</a>
                        <span class="glider" aria-hidden="true"></span>
                    </div>

                    <?php if ($notice !== ''): ?>
                        <p class="alert alert-<?php echo in_array($notice_type, ['error', 'success'], true) ? $notice_type : 'info'; ?>"><?php echo e($notice); ?></p>
                    <?php endif; ?>

                    <section class="panel" id="panel-signin" role="tabpanel" aria-labelledby="tab-signin" <?php echo $is_signup ? 'hidden' : ''; ?>>
                        <form method="POST" action="login.php" novalidate data-validate>
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="login">

                            <div class="field">
                                <label for="email">Email</label>
                                <input type="email" name="email" id="email" placeholder="you@gmail.com" autocomplete="username" maxlength="100" value="<?php echo e($old['signin_email']); ?>" required>
                            </div>
                            <div class="field">
                                <div class="label-row">
                                    <label for="password">Password</label>
                                    <a class="link-sm" href="forget_password.php">Forgot password?</a>
                                </div>
                                <div class="pw">
                                    <input type="password" name="password" id="password" placeholder="Your password" maxlength="<?php echo PASSWORD_MAX_LENGTH; ?>" autocomplete="current-password" required>
                                    <button type="button" class="pw-toggle" aria-label="Show password" aria-controls="password"><?php echo $eye; ?></button>
                                </div>
                            </div>

                            <?php if ($signin_error !== ''): ?>
                                <p class="alert alert-error" role="alert"><?php echo e($signin_error); ?><?php if ($unverified_email === ''): ?> <a href="forget_password.php">Reset your password</a>.<?php endif; ?></p>
                            <?php endif; ?>

                            <button type="submit" class="btn btn-primary btn-block">Sign in</button>
                        </form>

                        <?php if ($unverified_email !== ''): ?>
                            <form method="POST" action="login.php" class="resend-row">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="resend">
                                <input type="hidden" name="email" value="<?php echo e($unverified_email); ?>">
                                <button type="submit" class="btn btn-ghost btn-block">Send a new verification link</button>
                            </form>
                        <?php endif; ?>

                        <p class="switch">New to LADDER? <a href="login.php?mode=signup" data-mode="signup">Create an account</a></p>
                    </section>

                    <section class="panel" id="panel-signup" role="tabpanel" aria-labelledby="tab-signup" <?php echo $is_signup ? '' : 'hidden'; ?>>
                        <form method="POST" action="login.php" novalidate data-validate>
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="register">

                            <fieldset class="roles">
                                <legend>I am a</legend>
                                <label class="role">
                                    <input type="radio" name="user_type" value="student" <?php echo $old['role'] === 'student' ? 'checked' : ''; ?> required>
                                    <span class="role-box">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 9l10-5 10 5-10 5z"/><path d="M6 11v5c3 2 9 2 12 0v-5"/></svg>
                                        <span><b>Student</b><small>Take quizzes</small></span>
                                    </span>
                                </label>
                                <label class="role">
                                    <input type="radio" name="user_type" value="teacher" <?php echo $old['role'] === 'teacher' ? 'checked' : ''; ?>>
                                    <span class="role-box">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20h8M12 16v4"/></svg>
                                        <span><b>Teacher</b><small>Create quizzes</small></span>
                                    </span>
                                </label>
                            </fieldset>

                            <div class="field">
                                <label for="name">Full name</label>
                                <input type="text" name="name" id="name" placeholder="Your name" autocomplete="name" maxlength="50" value="<?php echo e($old['name']); ?>" required>
                            </div>
                            <div class="field">
                                <label for="reg-email">Email</label>
                                <input type="email" name="email" id="reg-email" placeholder="you@gmail.com" autocomplete="email" maxlength="100" value="<?php echo e($old['email']); ?>" required>
                            </div>
                            <div class="field" id="sid-field">
                                <label for="id">Student ID <span class="tag">Required</span></label>
                                <input type="text" name="id" id="id" placeholder="XXXX-X-XX-XXX" maxlength="50" pattern="[A-Za-z0-9][A-Za-z0-9._/\-]*" autocomplete="off" value="<?php echo e($old['id']); ?>">
                            </div>
                            <div class="field">
                                <label for="reg-password">Password</label>
                                <div class="pw">
                                    <input type="password" name="password" id="reg-password" placeholder="<?php echo e($pw_hint); ?>" minlength="<?php echo PASSWORD_MIN_LENGTH; ?>" maxlength="<?php echo PASSWORD_MAX_LENGTH; ?>" autocomplete="new-password" aria-describedby="pw-help" required>
                                    <button type="button" class="pw-toggle" aria-label="Show password" aria-controls="reg-password"><?php echo $eye; ?></button>
                                </div>
                                <p class="help" id="pw-help"><?php echo e($pw_hint); ?>, up to <?php echo PASSWORD_MAX_LENGTH; ?>.</p>
                            </div>

                            <?php if ($signup_error !== ''): ?>
                                <p class="alert alert-error" role="alert"><?php echo e($signup_error); ?></p>
                            <?php endif; ?>

                            <button type="submit" class="btn btn-primary btn-block">Create account</button>
                        </form>

                        <p class="switch">Already have an account? <a href="login.php" data-mode="signin">Sign in</a></p>
                    </section>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <script>
        (function () {
            var tablist = document.querySelector('.tabs');
            if (!tablist) return;
            var tabs = tablist.querySelectorAll('.tab');
            var title = document.getElementById('auth-title');
            var sub = document.getElementById('auth-sub');

            function show(mode, focusField) {
                tabs.forEach(function (t) {
                    var on = t.dataset.mode === mode;
                    t.classList.toggle('is-active', on);
                    t.setAttribute('aria-selected', on ? 'true' : 'false');
                    t.tabIndex = on ? 0 : -1;
                    document.getElementById(t.getAttribute('aria-controls')).hidden = !on;
                });
                tablist.dataset.mode = mode;
                title.textContent = title.dataset[mode];
                if (sub) sub.textContent = sub.dataset[mode];
                document.title = (mode === 'signup' ? 'Create account' : 'Sign in') + ' · LADDER';
                try { history.replaceState(null, '', mode === 'signup' ? 'login.php?mode=signup' : 'login.php'); } catch (e) {}
                if (focusField) {
                    var first = document.querySelector('#panel-' + mode + ' input:not([type=hidden]):not([type=radio])');
                    if (first) first.focus({ preventScroll: true });
                }
            }

            document.querySelectorAll('a[data-mode]').forEach(function (a) {
                a.addEventListener('click', function (e) {
                    e.preventDefault();
                    show(a.dataset.mode, !a.classList.contains('tab'));
                });
            });
            tablist.addEventListener('keydown', function (e) {
                if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') return;
                var next = tablist.dataset.mode === 'signup' ? 'signin' : 'signup';
                show(next, false);
                tablist.querySelector('[data-mode="' + next + '"]').focus();
            });
            tabs.forEach(function (t) { t.tabIndex = t.classList.contains('is-active') ? 0 : -1; });

            // Students must give their ID; teachers get one generated.
            var sidField = document.getElementById('sid-field');
            var sid = document.getElementById('id');
            function syncRole() {
                var r = document.querySelector('input[name="user_type"]:checked');
                var teacher = !!r && r.value === 'teacher';
                sidField.hidden = teacher;
                sid.disabled = teacher;
                sid.required = !teacher;
            }
            document.querySelectorAll('input[name="user_type"]').forEach(function (r) { r.addEventListener('change', syncRole); });
            syncRole();

            document.querySelectorAll('.pw-toggle').forEach(function (b) {
                b.addEventListener('click', function () {
                    var input = document.getElementById(b.getAttribute('aria-controls'));
                    var reveal = input.type === 'password';
                    input.type = reveal ? 'text' : 'password';
                    b.setAttribute('aria-label', reveal ? 'Hide password' : 'Show password');
                    b.classList.toggle('on', reveal);
                });
            });

            // Inline validation; the server checks everything again.
            document.querySelectorAll('form[data-validate]').forEach(function (form) {
                form.addEventListener('submit', function (e) {
                    var bad = null;
                    form.querySelectorAll('input:not([type=hidden]):not(:disabled)').forEach(function (i) {
                        var field = i.closest('.field');
                        var ok = i.checkValidity();
                        if (field) field.classList.toggle('invalid', !ok);
                        if (!ok && !bad) bad = i;
                    });
                    if (bad) {
                        e.preventDefault();
                        bad.focus();
                        if (bad.reportValidity) bad.reportValidity();
                        return;
                    }
                    form.querySelector('button[type=submit]').classList.add('loading');
                });
                form.addEventListener('input', function (e) {
                    var field = e.target.closest('.field');
                    if (field && e.target.checkValidity()) field.classList.remove('invalid');
                });
            });
        })();
    </script>
</body>

</html>
