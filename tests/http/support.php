<?php
/**
 * Shared helpers for the HTTP (end-to-end) tests: a tiny cookie-keeping
 * browser and access to the app database for setup and cleanup. Test data
 * always uses @http-test.local addresses.
 */

final class Browser
{
    private string $jar;
    public int $status = 0;
    public string $location = '';
    public string $body = '';

    public function __construct(private string $base)
    {
        $this->jar = tempnam(sys_get_temp_dir(), 'quiz_jar');
    }

    public function __destruct()
    {
        @unlink($this->jar);
    }

    public function get(string $path): self
    {
        return $this->request($path, null);
    }

    /**
     * @param array|string $data form fields, or a raw string body (with $content_type)
     *
     * Forms under auth/ (login, sign-up, password reset) need a CSRF token like
     * every other form. When a test leaves it out, the page is fetched first
     * and its token copied, as a real browser would. Dashboard posts are sent
     * as given, so tests can still check that a missing token is refused.
     */
    public function post(string $path, $data, string $content_type = ''): self
    {
        if (is_array($data) && !isset($data['csrf_token']) && strpos(ltrim($path, '/'), 'auth/') === 0) {
            $data['csrf_token'] = $this->get($path)->csrf();
        }
        return $this->request($path, $data, $content_type);
    }

    /** Read the CSRF token from the last page. */
    public function csrf(): string
    {
        if (!preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $this->body, $m)) {
            fail("no CSRF token on page (HTTP {$this->status})");
        }
        return $m[1];
    }

    private function request(string $path, $data, string $content_type = ''): self
    {
        $ch = curl_init($this->base . '/' . ltrim($path, '/'));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEJAR => $this->jar,
            CURLOPT_COOKIEFILE => $this->jar,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 20,
        ]);
        if ($data !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            // An array holding a CURLFile is sent as multipart/form-data (file upload).
            $multipart = is_array($data) && array_filter($data, fn($v) => $v instanceof CURLFile);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $multipart ? $data : (is_array($data) ? http_build_query($data) : $data));
            if ($content_type !== '') {
                curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: $content_type"]);
            }
        }
        $body = curl_exec($ch);
        if ($body === false) {
            fail('HTTP request failed: ' . curl_error($ch) . '. Is the web server running? Set TEST_BASE_URL or use --no-http.');
        }
        $this->body = $body;
        $this->status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $this->location = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);

        if (preg_match('/(Fatal error|Warning|Notice|Deprecated)<\/b>:|Uncaught /', $this->body)) {
            fail("PHP error in output of $path:\n" . substr(strip_tags($this->body), 0, 400));
        }
        return $this;
    }
}

function http_base(): string
{
    return rtrim(env_value('TEST_BASE_URL', 'http://localhost/Quiz-App'), '/');
}

function http_app_db(): mysqli
{
    static $conn = null;
    if ($conn === null) {
        $conn = new mysqli(env_value('server_name', 'localhost'), env_value('username', 'root'), env_value('db_password', ''), env_value('db_name', ''));
        $conn->set_charset('utf8mb4');
    }
    return $conn;
}

function http_cleanup(): void
{
    $db = http_app_db();
    $db->query("DELETE FROM quizzes WHERE teacher_email LIKE '%@http-test.local'");
    $db->query("DELETE FROM sections WHERE teacher_email LIKE '%@http-test.local' OR name LIKE 'HTTP Test%'");
    $db->query("DELETE FROM section_students WHERE student_email LIKE '%@http-test.local'");
    $db->query("DELETE FROM quiz_attempts WHERE student_email LIKE '%@http-test.local'");
    // Bank questions and subjects belong to their teacher (foreign keys keep an
    // account with content from being deleted), so they go first.
    $db->query("DELETE FROM questions WHERE teacher_email LIKE '%@http-test.local'");
    $db->query("DELETE FROM subjects WHERE teacher_email LIKE '%@http-test.local'");
    $db->query("DELETE FROM reg_info WHERE email LIKE '%@http-test.local'");
    // Login/sign-up throttling counts test traffic too; reset it for test
    // accounts and this machine so repeated runs are not locked out.
    $db->query("DELETE FROM auth_throttle WHERE subject LIKE '%@http-test.local' OR subject IN ('127.0.0.1', '::1')");
}

const HTTP_PASSWORD = 'Http@1234';

/** Insert an account straight into the app database (teachers cannot sign up publicly). */
function http_make_account(string $role, string $email, string $name = 'HTTP Tester'): void
{
    $db = http_app_db();
    $hash = password_hash(HTTP_PASSWORD, PASSWORD_DEFAULT);
    $stmt = $db->prepare("INSERT INTO reg_info (full_name, email, pass, role) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("ssss", $name, $email, $hash, $role);
    $stmt->execute();
    $stmt->close();
}

/** Mark a test account's email as verified (the real link only exists in the email). */
function http_verify_email(string $email): void
{
    $stmt = http_app_db()->prepare("UPDATE reg_info SET email_verified = 1, verify_token_hash = NULL, verify_expires = NULL WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $stmt->close();
}

/** Log in and return the browser; asserts the redirect to the role's dashboard. */
function http_login(string $email, string $password = HTTP_PASSWORD): Browser
{
    $b = new Browser(http_base());
    $b->post('auth/login.php', ['email' => $email, 'password' => $password]);
    return $b;
}
