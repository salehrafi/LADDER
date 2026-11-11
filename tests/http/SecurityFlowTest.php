<?php
// Phase 1 security, end to end: headers, CSRF on auth forms, login lock-out,
// password reset codes, and sessions ended by a password change.
// Uses @http-test.local accounts only and removes them afterwards.

require_once __DIR__ . '/support.php';

function sec_headers(string $path): string
{
    $ch = curl_init(http_base() . '/' . ltrim($path, '/'));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 20]);
    $raw = (string) curl_exec($ch);
    $size = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    return substr($raw, 0, $size);
}

test('security setup: clean test accounts', function () {
    http_cleanup();
    http_make_account('student', 'sec-student@http-test.local');
    http_make_account('teacher', 'sec-teacher@http-test.local');
});

test('pages send anti-framing and no-sniff headers', function () {
    $h = sec_headers('auth/login.php');
    assert_contains('X-Frame-Options: DENY', $h);
    assert_contains("frame-ancestors 'none'", $h);
    assert_contains('X-Content-Type-Options: nosniff', $h);
    assert_contains('Referrer-Policy: same-origin', $h);
});

test('auth forms refuse a POST without a valid CSRF token', function () {
    foreach (['auth/login.php', 'auth/registration.php', 'auth/forget_password.php'] as $path) {
        $b = new Browser(http_base());
        $b->get($path);
        $b->post($path, ['csrf_token' => 'bad', 'email' => 'sec-student@http-test.local', 'password' => HTTP_PASSWORD]);
        assert_same(403, $b->status, $path);
    }
});

test('five failed logins lock the account for a while, even with the right password', function () {
    $email = 'sec-student@http-test.local';
    for ($i = 0; $i < 5; $i++) {
        $b = (new Browser(http_base()))->post('auth/login.php', ['email' => $email, 'password' => 'Wrong@9999']);
        assert_contains('Invalid email or password', $b->body, "attempt $i");
    }
    $b = (new Browser(http_base()))->post('auth/login.php', ['email' => $email, 'password' => HTTP_PASSWORD]);
    assert_same(200, $b->status, 'not logged in');
    assert_contains('Too many failed sign-in attempts', $b->body);

    http_app_db()->query("DELETE FROM auth_throttle WHERE subject IN ('$email', '127.0.0.1', '::1')");
    $b = http_login($email);
    assert_contains('studentDashboard.php', $b->location, 'works again once the window has passed');
});

test('forgot password gives the same answer for unknown emails', function () {
    $b = new Browser(http_base());
    $b->post('auth/forget_password.php', ['mailTo' => 'nobody-here@http-test.local']);
    assert_same(302, $b->status);
    assert_contains('otp_verification.php', $b->location);
    assert_contains('If nobody-here@http-test.local has an account', $b->get('auth/otp_verification.php')->body);
});

test('the reset-code page refuses a wrong code with one neutral message', function () {
    $b = new Browser(http_base());
    $b->post('auth/forget_password.php', ['mailTo' => 'nobody-here@http-test.local']);
    $b->post('auth/otp_verification.php', ['otp' => '000000']);
    assert_same(200, $b->status);
    assert_contains('not valid or has expired', $b->body);
    $b->get('auth/password_change.php');
    assert_contains('forget_password.php', $b->location, 'cannot skip to choosing a password');
});

test('changing a password signs out the account\'s other sessions', function () {
    $email = 'sec-teacher@http-test.local';
    http_app_db()->query("DELETE FROM auth_throttle WHERE subject IN ('$email', '127.0.0.1', '::1')");
    $laptop = http_login($email);
    $phone = http_login($email);
    assert_same(200, $phone->get('dashboards/teacherDashboard.php')->status);

    $token = $laptop->get('dashboards/settings.php')->csrf();
    $laptop->post('dashboards/settings.php', [
        'csrf_token' => $token, 'form' => 'password',
        'current_password' => HTTP_PASSWORD, 'new_password' => 'Changed@123', 'repeat_password' => 'Changed@123',
    ]);
    assert_contains('Password updated', $laptop->body);
    assert_same(200, $laptop->get('dashboards/teacherDashboard.php')->status, 'this session stays signed in');

    $phone->get('dashboards/teacherDashboard.php');
    assert_same(302, $phone->status, 'the other session is signed out');
    assert_contains('login.php', $phone->location);
    assert_contains('your password was changed', strtolower($phone->get('auth/login.php')->body));
});

test('security teardown', function () {
    http_cleanup();
    assert_same(0, (int) http_app_db()->query("SELECT COUNT(*) c FROM reg_info WHERE email LIKE '%@http-test.local'")->fetch_assoc()['c']);
});
