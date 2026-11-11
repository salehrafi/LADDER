<?php
// Practice mode, instant feedback and bookmarks, end to end.
// Uses @http-test.local accounts only.

require_once __DIR__ . '/support.php';

function prf_one(string $sql): ?array
{
    return http_app_db()->query($sql)->fetch_assoc() ?: null;
}

test('practice setup: a practice-only quiz and an official quiz for one student', function () {
    http_cleanup();
    $db = http_app_db();
    http_make_account('teacher', 'prf-t@http-test.local');
    http_make_account('student', 'prf-s@http-test.local');
    $db->query("INSERT INTO sections (teacher_email, name) VALUES ('prf-t@http-test.local', 'HTTP Test Practice')");
    $sid = (int) $db->insert_id;
    $db->query("INSERT INTO section_students (section_id, student_email) VALUES ($sid, 'prf-s@http-test.local')");
    $ids = [];
    foreach ([['Drill Quiz', 'practice'], ['Graded Quiz', 'official']] as [$title, $mode]) {
        $db->query("INSERT INTO quizzes (teacher_email, title, status, mode, lock_on_leave, max_attempts, results_release) VALUES ('prf-t@http-test.local', '$title', 'published', '$mode', 1, 1, 'manual')");
        $quiz_id = (int) $db->insert_id;
        $db->query("INSERT INTO questions (quiz_id, teacher_email, type, question_text, points, order_index, explanation) VALUES ($quiz_id, 'prf-t@http-test.local', 'true_false', 'The Sun is a star.', 1, 0, 'It is a G-type star.')");
        $qid = (int) $db->insert_id;
        $db->query("INSERT INTO question_options (question_id, option_text, is_correct, order_index) VALUES ($qid, 'True', 1, 0), ($qid, 'False', 0, 1)");
        $ids[$mode] = ['quiz' => $quiz_id, 'question' => $qid];
    }
    $GLOBALS['prf'] = $ids + ['s' => http_login('prf-s@http-test.local')];
});

test('the practice page lists the practice quiz; the official one is not yet open for practice', function () {
    $s = $GLOBALS['prf']['s'] ?? fail('setup did not run');
    $body = $s->get('dashboards/studentPages/practice.php')->body;
    assert_contains('Drill Quiz', $body);
    assert_not_contains('Graded Quiz', $body, 'practice is not enabled on it');
    assert_not_contains('Drill Quiz', $s->get('dashboards/studentPages/quizzes.php')->body, 'practice quizzes stay off the graded list');
});

test('a practice attempt has no exam lock and gives instant feedback', function () {
    $p = $GLOBALS['prf'];
    $s = $p['s'];
    $quiz_id = $p['practice']['quiz'];
    $s->get("dashboards/studentPages/take_quiz.php?quiz_id=$quiz_id&practice=1&start=1");
    assert_contains('practice=1', $s->location);
    $page = $s->get("dashboards/studentPages/take_quiz.php?quiz_id=$quiz_id&practice=1")->body;
    assert_contains('const LOCKED = false', $page, 'exam mode is off in practice');
    assert_contains('Check answer', $page);
    assert_contains('The Sun is a star.', $page, 'no start gate: questions are on the page');
    preg_match('/name="attempt_id" value="(\d+)"/', $page, $m) || fail('no attempt');
    $attempt_id = (int) $m[1];
    $true = prf_one("SELECT id FROM question_options WHERE question_id = {$p['practice']['question']} AND is_correct = 1")['id'];
    $token = $s->csrf();

    $s->post('dashboards/studentPages/check_answer.php', json_encode(['attempt_id' => $attempt_id, 'csrf_token' => $token, 'question_id' => $p['practice']['question'], 'answer' => (string) $true]), 'application/json');
    $data = json_decode($s->body, true);
    assert_same(true, $data['correct'] ?? null);
    assert_same('It is a G-type star.', $data['explanation'] ?? null);

    $s->post('dashboards/studentPages/take_quiz.php', ['csrf_token' => $token, 'action' => 'submit_attempt', 'quiz_id' => $quiz_id, 'attempt_id' => $attempt_id, 'practice' => '1', 'q_' . $p['practice']['question'] => $true]);
    $review = $s->get("dashboards/studentPages/view_attempt.php?attempt_id=$attempt_id")->body;
    assert_contains('Practice attempt', $review);
    assert_contains('It is a G-type star.', $review, 'practice shows answers and explanations straight away');
    assert_contains('Practise again', $review);

    // Unlimited: a second practice attempt, although the quiz allows one attempt.
    $s->get("dashboards/studentPages/take_quiz.php?quiz_id=$quiz_id&practice=1&start=1");
    assert_same(2, (int) prf_one("SELECT COUNT(*) c FROM quiz_attempts WHERE quiz_id = $quiz_id AND is_practice = 1")['c']);
    $GLOBALS['prf']['practice_attempt'] = $attempt_id;
});

test('instant feedback is refused during an official attempt', function () {
    $p = $GLOBALS['prf'];
    $s = $p['s'];
    $quiz_id = $p['official']['quiz'];
    $s->get("dashboards/studentPages/take_quiz.php?quiz_id=$quiz_id&start=1");
    $page = $s->get("dashboards/studentPages/take_quiz.php?quiz_id=$quiz_id")->body;
    preg_match('/name="attempt_id" value="(\d+)"/', $page, $m) || fail('no attempt');
    assert_not_contains('Check answer', $page);
    $s->post('dashboards/studentPages/check_answer.php', json_encode(['attempt_id' => (int) $m[1], 'csrf_token' => $s->csrf(), 'question_id' => $p['official']['question'], 'answer' => '1']), 'application/json');
    assert_same(403, $s->status);
    // ?practice=1 on a quiz without practice cannot sneak in a practice attempt.
    $s->get("dashboards/studentPages/take_quiz.php?quiz_id=$quiz_id&practice=1&start=1");
    assert_contains('practice.php', $s->location);
    assert_same(0, (int) prf_one("SELECT COUNT(*) c FROM quiz_attempts WHERE quiz_id = $quiz_id AND is_practice = 1")['c']);
});

test('a question can be bookmarked from the review and revisited with a note', function () {
    $p = $GLOBALS['prf'];
    $s = $p['s'];
    $view = "dashboards/studentPages/view_attempt.php?attempt_id={$p['practice_attempt']}";
    $token = $s->get($view)->csrf();
    $s->post($view, ['csrf_token' => $token, 'action' => 'toggle_bookmark', 'question_id' => $p['practice']['question']]);
    assert_same(302, $s->status);
    assert_contains('aria-pressed="true"', $s->get($view)->body);

    $page = $s->get('dashboards/studentPages/bookmarks.php')->body;
    assert_contains('The Sun is a star.', $page);
    $bookmark = prf_one("SELECT id FROM question_bookmarks WHERE student_email = 'prf-s@http-test.local'");
    $s->post('dashboards/studentPages/bookmarks.php', ['csrf_token' => $s->csrf(), 'action' => 'save_note', 'bookmark_id' => $bookmark['id'], 'note' => 'Stars shine by fusion']);
    assert_contains('Stars shine by fusion', $s->get('dashboards/studentPages/bookmarks.php')->body);
});

test('practice attempts do not appear in the teacher\'s official results', function () {
    $t = http_login('prf-t@http-test.local');
    $body = $t->get('dashboards/teachersPages/results.php?quiz_id=' . $GLOBALS['prf']['practice']['quiz'])->body;
    assert_not_contains('prf-s@http-test.local', $body);
});

test('practice teardown', function () {
    http_cleanup();
    assert_same(0, (int) prf_one("SELECT COUNT(*) c FROM reg_info WHERE email LIKE '%@http-test.local'")['c']);
});
