<?php
// Assignments, late submissions, completion tracking and the notification
// center, end to end. Uses @http-test.local accounts only.

require_once __DIR__ . '/support.php';

function asf_one(string $sql): ?array
{
    return http_app_db()->query($sql)->fetch_assoc() ?: null;
}

test('assignment setup: a teacher, two students in one section, a draft quiz', function () {
    http_cleanup();
    $db = http_app_db();
    http_make_account('teacher', 'asf-t@http-test.local');
    http_make_account('student', 'asf-in@http-test.local', 'Picked Student');
    http_make_account('student', 'asf-out@http-test.local', 'Other Student');
    $db->query("INSERT INTO sections (teacher_email, name) VALUES ('asf-t@http-test.local', 'HTTP Test Assign')");
    $sid = (int) $db->insert_id;
    $db->query("INSERT INTO section_students (section_id, student_email) VALUES ($sid, 'asf-in@http-test.local'), ($sid, 'asf-out@http-test.local')");
    $db->query("INSERT INTO quizzes (teacher_email, title, status, lock_on_leave, max_attempts, results_release) VALUES ('asf-t@http-test.local', 'Assigned Quiz', 'draft', 0, 1, 'immediate')");
    $quiz_id = (int) $db->insert_id;
    $db->query("INSERT INTO questions (quiz_id, teacher_email, type, question_text, points, order_index) VALUES ($quiz_id, 'asf-t@http-test.local', 'true_false', 'Is water wet?', 1, 0)");
    $qid = (int) $db->insert_id;
    $db->query("INSERT INTO question_options (question_id, option_text, is_correct, order_index) VALUES ($qid, 'True', 1, 0), ($qid, 'False', 0, 1)");
    $GLOBALS['asf'] = ['quiz_id' => $quiz_id, 't' => http_login('asf-t@http-test.local')];
});

test('teacher assigns the quiz to one student with a past due date and late window, then publishes', function () {
    $t = $GLOBALS['asf']['t'] ?? fail('setup did not run');
    $quiz_id = $GLOBALS['asf']['quiz_id'];
    $page = "dashboards/teachersPages/assignments.php?quiz_id=$quiz_id";
    $token = $t->get($page)->csrf();
    assert_contains('Assign: Assigned Quiz', $t->body);

    $t->post($page, [
        'csrf_token' => $token, 'action' => 'assign', 'quiz_id' => $quiz_id, 'target_type' => 'student',
        'students' => ['asf-in@http-test.local'],
        'due_at' => date('Y-m-d\TH:i', strtotime('-1 hour')), 'late_until' => date('Y-m-d\TH:i', strtotime('+1 day')),
    ]);
    assert_same(302, $t->status);
    assert_same(1, (int) asf_one("SELECT COUNT(*) c FROM quiz_assignments WHERE quiz_id = $quiz_id AND student_email = 'asf-in@http-test.local'")['c']);
    assert_same(null, asf_one("SELECT id FROM notifications WHERE user_email = 'asf-in@http-test.local'"), 'draft: nobody notified yet');

    // Bad dates are refused with a message.
    $t->post($page, ['csrf_token' => $token, 'action' => 'assign', 'quiz_id' => $quiz_id, 'target_type' => 'all',
        'due_at' => date('Y-m-d\TH:i', strtotime('+1 day')), 'late_until' => date('Y-m-d\TH:i', strtotime('-1 day'))]);
    assert_contains('must be after the due date', $t->body);

    $token = $t->get('dashboards/teachersPages/setQuiz.php')->csrf();
    $t->post('dashboards/teachersPages/setQuiz.php', ['csrf_token' => $token, 'action' => 'set_status', 'quiz_id' => $quiz_id, 'status' => 'published']);
    assert_contains('1 student(s) notified', $t->body);
});

test('the assigned student is notified and can submit late; the other student cannot see the quiz', function () {
    $quiz_id = $GLOBALS['asf']['quiz_id'] ?? fail('setup did not run');
    $s = http_login('asf-in@http-test.local');
    $dash = $s->get('dashboards/studentDashboard.php')->body;
    assert_contains('Notifications', $dash);
    assert_contains('nav-count', $dash, 'unread badge in the menu');

    $inbox = $s->get('dashboards/notifications.php')->body;
    assert_contains('New quiz: Assigned Quiz', $inbox);
    preg_match('/notifications\.php\?open=(\d+)/', $inbox, $m) || fail('no notification link');
    $s->get('dashboards/notifications.php?open=' . $m[1]);
    assert_contains('studentPages/quizzes.php', $s->location, 'opening follows the link');
    assert_true(asf_one("SELECT read_at FROM notifications WHERE id = {$m[1]}")['read_at'] !== null, 'and marks it read');
    $GLOBALS['asf']['notification_id'] = (int) $m[1];

    $list = $s->get('dashboards/studentPages/quizzes.php')->body;
    assert_contains('Assigned Quiz', $list);
    assert_contains('Start (late)', $list);
    assert_contains('late submissions accepted until', $list);

    $s->get("dashboards/studentPages/take_quiz.php?quiz_id=$quiz_id&start=1");
    $page = $s->get("dashboards/studentPages/take_quiz.php?quiz_id=$quiz_id")->body;
    preg_match('/name="attempt_id" value="(\d+)"/', $page, $a) || fail('no attempt');
    preg_match('/name="(q_\d+)" value="(\d+)"/', $page, $o) || fail('no option');
    $s->post('dashboards/studentPages/take_quiz.php', ['csrf_token' => $s->csrf(), 'action' => 'submit_attempt', 'quiz_id' => $quiz_id, 'attempt_id' => $a[1], $o[1] => $o[2]]);
    $row = asf_one("SELECT status, due_at, submitted_at > due_at AS late FROM quiz_attempts WHERE id = {$a[1]}");
    assert_same('submitted', $row['status']);
    assert_same('1', (string) $row['late']);

    $other = http_login('asf-out@http-test.local');
    assert_not_contains('Assigned Quiz', $other->get('dashboards/studentPages/quizzes.php')->body);
    $other->get("dashboards/studentPages/take_quiz.php?quiz_id=$quiz_id&start=1");
    assert_contains('quizzes.php', $other->location);
    assert_same(null, asf_one("SELECT id FROM quiz_attempts WHERE student_email = 'asf-out@http-test.local'"));
    // Someone else's notification cannot be opened or marked read.
    $other->get('dashboards/notifications.php?open=' . $GLOBALS['asf']['notification_id']);
    assert_contains('notifications.php', $other->location);
});

test('the teacher sees who completed it, and that it was late', function () {
    $t = $GLOBALS['asf']['t'];
    $body = $t->get("dashboards/teachersPages/assignments.php?quiz_id={$GLOBALS['asf']['quiz_id']}")->body;
    assert_contains('Picked Student', $body);
    assert_contains('Completed late', $body);
    $completion = substr($body, strpos($body, 'aria-label="Completion"'));
    assert_not_contains('Other Student', $completion, 'only assigned students are tracked');
});

test('assignment teardown', function () {
    http_cleanup();
    assert_same(0, (int) asf_one("SELECT COUNT(*) c FROM reg_info WHERE email LIKE '%@http-test.local'")['c']);
});
