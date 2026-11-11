<?php
// Quiz assignments (who and when), availability windows, lateness and
// completion status, and notifications. Runs on the isolated test database.
require_once APP_ROOT . '/includes/class_helpers.php';
require_once APP_ROOT . '/includes/assignments.php';
require_once APP_ROOT . '/includes/notifications.php';

const AS_T = 'as-t@test.local';
const AS_T2 = 'as-t2@test.local';

function as_fixture(): array
{
    reset_test_db();
    $conn = test_db();
    $conn->query("DELETE FROM section_students");
    $conn->query("DELETE FROM sections");
    foreach ([AS_T, AS_T2] as $t) {
        ensure_test_account($conn, $t, 'teacher');
    }
    foreach (['a', 'b', 'c', 'x'] as $s) {
        ensure_test_account($conn, "as-$s@test.local", 'student');
    }
    $sec1 = ensure_section($conn, AS_T, 'Sec 1');
    $sec2 = ensure_section($conn, AS_T, 'Sec 2');
    $theirs = ensure_section($conn, AS_T2, 'Theirs');
    $conn->query("INSERT INTO section_students (section_id, student_email) VALUES
        ($sec1, 'as-a@test.local'), ($sec1, 'as-b@test.local'), ($sec2, 'as-c@test.local'), ($theirs, 'as-x@test.local')");
    $quiz = make_quiz($conn, [['mcq', 'Q', 1, null, ['yes' => true, 'no' => false]]], ['teacher' => AS_T, 'max_attempts' => 0]);
    return [$conn, $quiz['quiz_id'], $sec1, $sec2, $theirs, $quiz];
}

function as_quiz(mysqli $conn, int $id): array
{
    return $conn->query("SELECT * FROM quizzes WHERE id = $id")->fetch_assoc();
}

function as_time(string $offset): string
{
    return date('Y-m-d H:i:s', strtotime($offset));
}

test('a quiz with no assignments is open to all of the teacher\'s students only', function () {
    [$conn, $qid] = as_fixture();
    $q = as_quiz($conn, $qid);
    assert_true(quiz_visible_to_student($conn, $qid, 'as-a@test.local'));
    assert_true(quiz_visible_to_student($conn, $qid, 'as-c@test.local'));
    assert_false(quiz_visible_to_student($conn, $qid, 'as-x@test.local'), "another teacher's student");
    assert_same('none', student_window($conn, $q, 'as-a@test.local')['source']);
});

test('assign_quiz() targets sections or students, and checks ownership and dates', function () {
    [$conn, $qid, $sec1, $sec2, $theirs] = as_fixture();
    assert_same(1, assign_quiz($conn, AS_T, $qid, 'section', [$sec1, $theirs, 9999], null, null, null), 'only own sections');
    assert_true(quiz_visible_to_student($conn, $qid, 'as-a@test.local'));
    assert_false(quiz_visible_to_student($conn, $qid, 'as-c@test.local'), 'section 2 is not assigned');

    assert_same(1, assign_quiz($conn, AS_T, $qid, 'student', ['as-c@test.local', 'as-x@test.local'], null, null, null));
    assert_true(quiz_visible_to_student($conn, $qid, 'as-c@test.local'), 'assigned personally');
    assert_false(quiz_visible_to_student($conn, $qid, 'as-x@test.local'));

    $bad = [
        [AS_T2, 'all', [], null, null, null, 'Quiz not found.'],
        [AS_T, 'section', [$theirs], null, null, null, 'Tick at least one'],
        [AS_T, 'all', [], as_time('+2 days'), as_time('+1 day'), null, 'due date must be after'],
        [AS_T, 'all', [], null, null, as_time('+1 day'), 'Set a due date before'],
        [AS_T, 'all', [], null, as_time('+2 days'), as_time('+1 day'), 'must be after the due date'],
    ];
    foreach ($bad as [$teacher, $type, $targets, $from, $due, $late, $msg]) {
        try {
            assign_quiz($conn, $teacher, $qid, $type, $targets, $from, $due, $late);
            fail("expected: $msg");
        } catch (InvalidArgumentException $e) {
            assert_contains($msg, $e->getMessage());
        }
    }
    // Re-assigning the same target updates its window instead of duplicating it.
    assign_quiz($conn, AS_T, $qid, 'section', [$sec1], null, as_time('+3 days'), null);
    assert_same(1, (int) $conn->query("SELECT COUNT(*) c FROM quiz_assignments WHERE quiz_id = $qid AND target_type = 'section'")->fetch_assoc()['c']);
});

test('the most specific assignment wins, then the most generous deadline', function () {
    [$conn, $qid, $sec1, $sec2] = as_fixture();
    $conn->query("UPDATE quizzes SET end_time = '" . as_time('+10 days') . "' WHERE id = $qid");
    assign_quiz($conn, AS_T, $qid, 'all', [], null, as_time('+1 day'), null);
    assign_quiz($conn, AS_T, $qid, 'section', [$sec1], null, as_time('+2 days'), null);
    assign_quiz($conn, AS_T, $qid, 'student', ['as-b@test.local'], null, as_time('+5 days'), null);
    $q = as_quiz($conn, $qid);

    assert_same('student', student_window($conn, $q, 'as-b@test.local')['source']);
    assert_same(as_time('+5 days'), student_window($conn, $q, 'as-b@test.local')['due_at']);
    assert_same('section', student_window($conn, $q, 'as-a@test.local')['source']);
    assert_same('all', student_window($conn, $q, 'as-c@test.local')['source']);

    // An assignment without dates falls back to the quiz's own times.
    $conn->query("DELETE FROM quiz_assignments WHERE quiz_id = $qid");
    assign_quiz($conn, AS_T, $qid, 'section', [$sec2], null, null, null);
    assert_same(as_time('+10 days'), student_window($conn, as_quiz($conn, $qid), 'as-c@test.local')['due_at']);
    assert_same(null, student_window($conn, as_quiz($conn, $qid), 'as-a@test.local'), 'not assigned to section 1');
});

test('window_availability() covers upcoming, open, late, past and closed', function () {
    $quiz = ['status' => 'published'];
    $w = fn($from, $due, $late) => ['available_from' => $from, 'due_at' => $due, 'late_until' => $late, 'source' => 'all'];
    assert_same('upcoming', window_availability($quiz, $w(as_time('+1 hour'), null, null))['state']);
    assert_same('open', window_availability($quiz, $w(null, as_time('+1 hour'), null))['state']);
    $late = window_availability($quiz, $w(null, as_time('-1 hour'), as_time('+1 hour')));
    assert_true($late['open'] && $late['late']);
    assert_same('past', window_availability($quiz, $w(null, as_time('-2 hours'), as_time('-1 hour')))['state']);
    assert_same('closed', window_availability(['status' => 'closed'], $w(null, null, null))['state']);
    assert_same('unassigned', window_availability($quiz, null)['state']);
});

test('attempts remember their deadline; late and overdue statuses follow from it', function () {
    [$conn, $qid, $sec1] = as_fixture();
    assign_quiz($conn, AS_T, $qid, 'section', [$sec1], null, as_time('-1 hour'), as_time('+1 day'));
    $q = as_quiz($conn, $qid);
    $window = student_window($conn, $q, 'as-a@test.local');
    assert_true(window_availability($q, $window)['late'], 'late submissions accepted');

    $attempt = start_or_resume_attempt($conn, $q, 'as-a@test.local', $window['due_at'])['attempt'];
    assert_same($window['due_at'], $attempt['due_at']);
    finalize_attempt($conn, $attempt, []);
    $row = $conn->query("SELECT * FROM quiz_attempts WHERE id = {$attempt['id']}")->fetch_assoc();
    assert_true(attempt_is_late($row));
    $summary = student_attempt_summary($conn, $qid, 'as-a@test.local');
    assert_same('completed_late', student_quiz_status($window, $summary));

    $b = student_attempt_summary($conn, $qid, 'as-b@test.local');
    assert_same('overdue', student_quiz_status($window, $b));

    // Moving the deadline later does not rewrite history.
    $conn->query("UPDATE quiz_assignments SET due_at = '" . as_time('+2 days') . "' WHERE quiz_id = $qid");
    assert_true(attempt_is_late($conn->query("SELECT * FROM quiz_attempts WHERE id = {$attempt['id']}")->fetch_assoc()));
});

test('assignment_roster() lists everyone assigned with their status', function () {
    [$conn, $qid, $sec1, $sec2] = as_fixture();
    assign_quiz($conn, AS_T, $qid, 'section', [$sec1], null, as_time('+1 day'), null);
    $q = as_quiz($conn, $qid);
    finalize_attempt($conn, start_or_resume_attempt($conn, $q, 'as-a@test.local')['attempt'], []);
    $roster = assignment_roster($conn, AS_T, $q);
    assert_same(['as-a@test.local', 'as-b@test.local'], array_column($roster, 'email'));
    assert_same(['completed', 'not_started'], array_column($roster, 'status'));
});

test('notify() collapses repeats of the same event and keeps inboxes private', function () {
    [$conn] = as_fixture();
    assert_same(2, notify($conn, ['as-a@test.local', 'as-b@test.local'], 'assigned', 'Hello', null, null, 'k1'));
    assert_same(0, notify($conn, 'as-a@test.local', 'assigned', 'Hello again', null, null, 'k1'), 'duplicate key');
    assert_same(0, notify($conn, 'nobody@test.local', 'assigned', 'Ghost'), 'unknown users are skipped');
    assert_same(1, unread_notification_count($conn, 'as-a@test.local'));

    $id = (int) user_notifications($conn, 'as-a@test.local')['rows'][0]['id'];
    assert_same(null, find_notification($conn, 'as-b@test.local', $id), "not someone else's notification");
    mark_notifications_read($conn, 'as-b@test.local', $id);
    assert_same(1, unread_notification_count($conn, 'as-a@test.local'), 'another user cannot mark it read');
    mark_notifications_read($conn, 'as-a@test.local');
    assert_same(0, unread_notification_count($conn, 'as-a@test.local'));
});

test('assignment, deadline, results, feedback and pending-evaluation events notify the right people', function () {
    [$conn, $qid, $sec1] = as_fixture();
    $conn->query("UPDATE quizzes SET status = 'draft' WHERE id = $qid");
    assign_quiz($conn, AS_T, $qid, 'section', [$sec1], null, as_time('+5 hours'), null);
    assert_same(0, notify_quiz_assigned($conn, AS_T, $qid), 'drafts do not notify');
    $conn->query("UPDATE quizzes SET status = 'published', results_release = 'manual' WHERE id = $qid");
    assert_same(2, notify_quiz_assigned($conn, AS_T, $qid));
    assert_same(0, notify_quiz_assigned($conn, AS_T, $qid), 'once per quiz');
    assert_same(0, unread_notification_count($conn, 'as-c@test.local'), 'section 2 not assigned');

    $q = as_quiz($conn, $qid);
    $w = student_window($conn, $q, 'as-a@test.local');
    $rows = [array_merge($q, ['window' => $w, 'avail' => window_availability($q, $w), 'summary' => student_attempt_summary($conn, $qid, 'as-a@test.local')])];
    notify_upcoming_deadlines($conn, 'as-a@test.local', $rows);
    notify_upcoming_deadlines($conn, 'as-a@test.local', $rows);
    assert_same(1, (int) $conn->query("SELECT COUNT(*) c FROM notifications WHERE user_email = 'as-a@test.local' AND type = 'due_soon'")->fetch_assoc()['c']);

    // Submitting a quiz with an ungraded short answer tells the teacher.
    $conn->query("INSERT INTO questions (quiz_id, teacher_email, type, question_text, points, order_index) VALUES ($qid, '" . AS_T . "', 'short_answer', 'Explain', 1, 5)");
    $attempt = start_or_resume_attempt($conn, $q, 'as-a@test.local')['attempt'];
    finalize_attempt($conn, $attempt, []);
    assert_same(1, (int) $conn->query("SELECT COUNT(*) c FROM notifications WHERE user_email = '" . AS_T . "' AND type = 'pending'")->fetch_assoc()['c']);

    // Feedback waits for the release; the release notifies.
    $answer = (int) $conn->query("SELECT id FROM attempt_answers WHERE attempt_id = {$attempt['id']} AND points_awarded IS NULL")->fetch_assoc()['id'];
    save_teacher_grades($conn, $attempt, AS_T, [$answer => '1'], [$answer => 'Well argued']);
    assert_same(0, (int) $conn->query("SELECT COUNT(*) c FROM notifications WHERE type = 'feedback'")->fetch_assoc()['c'], 'results not released yet');
    $conn->query("UPDATE quizzes SET results_released_at = NOW() WHERE id = $qid");
    assert_same(1, notify_results_released($conn, $qid));
    save_teacher_grades($conn, $attempt, AS_T, [$answer => '1'], [$answer => 'Well argued, and clear']);
    assert_same(1, (int) $conn->query("SELECT COUNT(*) c FROM notifications WHERE type = 'feedback' AND user_email = 'as-a@test.local'")->fetch_assoc()['c']);
});

test('results that become visible when the end time passes notify on the next visit', function () {
    [$conn, $qid] = as_fixture();
    $q = as_quiz($conn, $qid);
    finalize_attempt($conn, start_or_resume_attempt($conn, $q, 'as-a@test.local')['attempt'], []);
    $conn->query("UPDATE quizzes SET results_release = 'after_close', end_time = '" . as_time('+1 hour') . "' WHERE id = $qid");
    notify_newly_visible_results($conn, 'as-a@test.local');
    assert_same(0, unread_notification_count($conn, 'as-a@test.local'), 'not visible yet');
    $conn->query("UPDATE quizzes SET end_time = '" . as_time('-1 minute') . "' WHERE id = $qid");
    notify_newly_visible_results($conn, 'as-a@test.local');
    notify_newly_visible_results($conn, 'as-a@test.local');
    assert_same(1, unread_notification_count($conn, 'as-a@test.local'));
});
