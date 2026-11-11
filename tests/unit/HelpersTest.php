<?php
// Pure functions in includes/helpers.php and includes/quiz_helpers.php.

test('e() escapes HTML special characters', function () {
    assert_same('&lt;script&gt;&quot;x&quot; &amp; &#039;y&#039;', e('<script>"x" & \'y\''));
    assert_same('', e(null));
    assert_same('42', e(42));
});

test('fmt_score() trims trailing zeros', function () {
    assert_same('-', fmt_score(null));
    assert_same('5', fmt_score('5.00'));
    assert_same('2.5', fmt_score(2.50));
    assert_same('0.25', fmt_score('0.25'));
    assert_same('0', fmt_score(0));
});

test('attempts_label() shows Unlimited for 0', function () {
    assert_same('Unlimited', attempts_label(0));
    assert_same('3', attempts_label(3));
});

test('quiz_availability() handles draft, closed and time windows', function () {
    $base = ['status' => 'published', 'start_time' => null, 'end_time' => null];

    assert_false(quiz_availability(['status' => 'draft'] + $base)['open']);
    assert_false(quiz_availability(['status' => 'closed'] + $base)['open']);
    assert_true(quiz_availability($base)['open']);

    $future = date('Y-m-d H:i:s', time() + 3600);
    $past = date('Y-m-d H:i:s', time() - 3600);

    $not_yet = quiz_availability(['start_time' => $future] + $base);
    assert_false($not_yet['open']);
    assert_contains('Opens', $not_yet['label']);

    $expired = quiz_availability(['end_time' => $past] + $base);
    assert_false($expired['open']);

    $window = quiz_availability(['start_time' => $past, 'end_time' => $future] + $base);
    assert_true($window['open']);
    assert_contains('Open until', $window['label']);
});

test('attempt_deadline_epoch() is null without a time limit', function () {
    assert_same(null, attempt_deadline_epoch(['time_limit_minutes' => null, 'started_at' => '2026-01-01 10:00:00']));
    assert_same(
        strtotime('2026-01-01 10:00:00') + 600,
        attempt_deadline_epoch(['time_limit_minutes' => 10, 'started_at' => '2026-01-01 10:00:00'])
    );
});

test('load_draft() tolerates missing or corrupt JSON', function () {
    assert_same([], load_draft([]));
    assert_same([], load_draft(['draft_answers' => 'not json']));
    assert_same(['q_1' => '5'], load_draft(['draft_answers' => '{"q_1":"5"}']));
});

test('normalize_short_answer() ignores case and spacing, including non-ASCII', function () {
    assert_same('photo synthesis', normalize_short_answer("  Photo   SYNTHESIS \n"));
    assert_same(normalize_short_answer('ÉCOLE'), normalize_short_answer('école'));
    assert_same('', normalize_short_answer(null));
});

test('csv_safe() neutralises spreadsheet formulas', function () {
    assert_same("'=HYPERLINK(\"x\")", csv_safe('=HYPERLINK("x")'));
    assert_same("'+1", csv_safe('+1'));
    assert_same("'@SUM(A1)", csv_safe('@SUM(A1)'));
    assert_same('Rafi Ahmed', csv_safe('Rafi Ahmed'));
    assert_same('', csv_safe(''));
});

test('CSRF token is stable per session and validated in constant time', function () {
    $_SESSION = [];
    $token = csrf_token();
    assert_same(64, strlen($token));
    assert_same($token, csrf_token(), 'token should not change within a session');
    assert_true(csrf_valid($token));
    assert_false(csrf_valid('wrong'));
    assert_false(csrf_valid(null));

    $_SESSION = [];
    assert_false(csrf_valid($token), 'no token in session means invalid');
});

test('verify_csrf() lets GET through without a token', function () {
    $_SESSION = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    verify_csrf(); // must not exit
    assert_true(true);
});
