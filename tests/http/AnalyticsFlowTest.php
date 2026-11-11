<?php
// Analytics pages, CSV exports and printable reports, end to end.
// Uses @http-test.local accounts only.

require_once __DIR__ . '/support.php';

function anf_one(string $sql): ?array
{
    return http_app_db()->query($sql)->fetch_assoc() ?: null;
}

test('analytics setup: a teacher, two students and a graded quiz', function () {
    http_cleanup();
    $db = http_app_db();
    http_make_account('teacher', 'anf-t@http-test.local');
    http_make_account('teacher', 'anf-t2@http-test.local');
    http_make_account('student', 'anf-s1@http-test.local', 'Strong Student');
    http_make_account('student', 'anf-s2@http-test.local', 'Learning Student');
    $db->query("INSERT INTO sections (teacher_email, name) VALUES ('anf-t@http-test.local', 'HTTP Test Analytics')");
    $sid = (int) $db->insert_id;
    $db->query("INSERT INTO section_students (section_id, student_email) VALUES ($sid, 'anf-s1@http-test.local'), ($sid, 'anf-s2@http-test.local')");
    $db->query("INSERT INTO quizzes (teacher_email, title, status, lock_on_leave, max_attempts, results_release) VALUES ('anf-t@http-test.local', 'Analytics Quiz', 'published', 0, 1, 'immediate')");
    $quiz_id = (int) $db->insert_id;
    $db->query("INSERT INTO questions (quiz_id, teacher_email, type, question_text, points, order_index) VALUES ($quiz_id, 'anf-t@http-test.local', 'true_false', 'Two is even.', 1, 0)");
    $qid = (int) $db->insert_id;
    $db->query("INSERT INTO question_options (question_id, option_text, is_correct, order_index) VALUES ($qid, 'True', 1, 0), ($qid, 'False', 0, 1)");
    $db->query("INSERT INTO quizzes (teacher_email, title, status) VALUES ('anf-t2@http-test.local', 'Their Secret Quiz', 'published')");
    $other_quiz = (int) $db->insert_id;

    // Each student takes the quiz through the real pages: one right, one wrong.
    foreach (['anf-s1@http-test.local' => 'True', 'anf-s2@http-test.local' => 'False'] as $email => $choice) {
        $s = http_login($email);
        $s->get("dashboards/studentPages/take_quiz.php?quiz_id=$quiz_id&start=1");
        $page = $s->get("dashboards/studentPages/take_quiz.php?quiz_id=$quiz_id")->body;
        preg_match('/name="attempt_id" value="(\d+)"/', $page, $a) || fail('no attempt');
        $opt = anf_one("SELECT id FROM question_options WHERE question_id = $qid AND option_text = '$choice'")['id'];
        $s->post('dashboards/studentPages/take_quiz.php', ['csrf_token' => $s->csrf(), 'action' => 'submit_attempt', 'quiz_id' => $quiz_id, 'attempt_id' => $a[1], "q_$qid" => $opt]);
    }
    $GLOBALS['anf'] = ['quiz_id' => $quiz_id, 'other_quiz' => $other_quiz, 't' => http_login('anf-t@http-test.local')];
});

test('the teacher analytics page shows the class summary and charts', function () {
    $t = $GLOBALS['anf']['t'] ?? fail('setup did not run');
    $body = $t->get('dashboards/teachersPages/analytics.php')->body;
    assert_same(200, $t->status);
    assert_contains('Class analytics', $body);
    assert_contains('<svg class="chart"', $body, 'score distribution chart');
    assert_contains('50%', $body, 'average of 100% and 0%');
    assert_contains('Strong Student', $body);
    assert_contains('data-print', $body);
});

test("another teacher's quiz cannot be selected as a filter", function () {
    $t = $GLOBALS['anf']['t'];
    $body = $t->get('dashboards/teachersPages/analytics.php?quiz=' . $GLOBALS['anf']['other_quiz'])->body;
    assert_contains('All quizzes', $body, 'unknown quiz id falls back to all quizzes');
    assert_not_contains('Their Secret Quiz', $body);
});

test('class analytics and question analysis export as CSV', function () {
    $t = $GLOBALS['anf']['t'];
    $csv = $t->get('dashboards/teachersPages/analytics.php?export=students')->body;
    assert_contains('Student,Email,Quizzes,Attempts,"Average %"', $csv);
    assert_contains('anf-s1@http-test.local', $csv);

    $csv = $t->get('dashboards/teachersPages/results.php?quiz_id=' . $GLOBALS['anf']['quiz_id'] . '&export=analysis')->body;
    assert_contains('Discrimination', $csv);
    assert_contains('Two is even.', $csv);
    assert_contains('True (key): 1', $csv, 'option counts');

    $page = $t->get('dashboards/teachersPages/results.php?quiz_id=' . $GLOBALS['anf']['quiz_id'])->body;
    assert_contains('Options chosen', $page);
    // Another teacher's quiz analysis is not reachable.
    $t->get('dashboards/teachersPages/results.php?quiz_id=' . $GLOBALS['anf']['other_quiz'] . '&export=analysis');
    assert_same(302, $t->status);
});

test('the student progress page shows their trend; students cannot open teacher analytics', function () {
    $s = http_login('anf-s1@http-test.local');
    $body = $s->get('dashboards/studentPages/progress.php')->body;
    assert_same(200, $s->status);
    assert_contains('My Progress', $body);
    assert_contains('Your trend appears after two graded results.', $body, 'one result is not a trend');
    assert_contains('100%', $body);
    assert_contains('Progress report:', $body, 'print-only report header');

    $s->get('dashboards/teachersPages/analytics.php');
    assert_same(302, $s->status);
    assert_contains('login.php', $s->location);
});

test('analytics teardown', function () {
    http_cleanup();
    assert_same(0, (int) anf_one("SELECT COUNT(*) c FROM reg_info WHERE email LIKE '%@http-test.local'")['c']);
});
