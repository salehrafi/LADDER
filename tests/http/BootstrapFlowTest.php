<?php
// Session cookie settings and JSON endpoint guards from includes/bootstrap.php.

require_once __DIR__ . '/support.php';

/** Raw response headers for a GET (the Browser class keeps only the body). */
function http_headers(string $path): string
{
    $ch = curl_init(http_base() . '/' . ltrim($path, '/'));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_NOBODY => false, CURLOPT_TIMEOUT => 20]);
    $raw = (string) curl_exec($ch);
    $size = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    return substr($raw, 0, $size);
}

test('secrets, internal code, tests and docs are not served', function () {
    foreach (['.env', 'db/conn.php', 'includes/helpers.php', 'vendor/autoload.php', 'PHPMailer/src/SMTP.php',
        'tests/run.php', 'docs/CODE_REVIEW.md', 'README.md'] as $path) {
        assert_same(403, (new Browser(http_base()))->get($path)->status, $path);
    }
    assert_same(200, (new Browser(http_base()))->get('index.php')->status);
});

test('the session cookie is HttpOnly and SameSite=Lax', function () {
    $headers = http_headers('auth/login.php');
    assert_true((bool) preg_match('/^Set-Cookie: PHPSESSID=[^\r\n]*/mi', $headers, $m), 'login page should start a session');
    assert_contains('HttpOnly', $m[0]);
    assert_contains('SameSite=Lax', $m[0]);
});

test('JSON endpoints refuse anonymous, GET and malformed requests with JSON errors', function () {
    foreach (['save_answer.php', 'begin_quiz.php', 'leave_quiz.php'] as $endpoint) {
        $path = "dashboards/studentPages/$endpoint";

        $b = new Browser(http_base());
        $b->post($path, '{}', 'application/json');
        assert_same(401, $b->status, "$endpoint anonymous");
        assert_same('login_required', json_decode($b->body, true)['error'] ?? null);
    }

    http_cleanup();
    http_make_account('student', 'boot-student@http-test.local');
    $s = http_login('boot-student@http-test.local');

    $s->get('dashboards/studentPages/save_answer.php');
    assert_same(405, $s->status);

    $s->post('dashboards/studentPages/begin_quiz.php', 'not json', 'application/json');
    assert_same(400, $s->status);
    assert_same('bad_json', json_decode($s->body, true)['error'] ?? null);

    $s->post('dashboards/studentPages/leave_quiz.php', json_encode(['attempt_id' => 1]), 'application/json');
    assert_same(403, $s->status);
    assert_same('bad_token', json_decode($s->body, true)['error'] ?? null);

    http_cleanup();
});
