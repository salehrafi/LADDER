<?php
// Practice mode and bookmarks. Runs on the isolated test database.
require_once APP_ROOT . '/includes/class_helpers.php';
require_once APP_ROOT . '/includes/assignments.php';
require_once APP_ROOT . '/includes/notifications.php';
require_once APP_ROOT . '/includes/practice.php';

const PR_T = 'pr-t@test.local';
const PR_S = 'pr-s@test.local';

function pr_fixture(array $quiz_settings = []): array
{
    reset_test_db();
    $conn = test_db();
    $conn->query("DELETE FROM section_students");
    $conn->query("DELETE FROM sections");
    ensure_test_account($conn, PR_T, 'teacher');
    ensure_test_account($conn, PR_S, 'student');
    ensure_test_account($conn, 'pr-outsider@test.local', 'student');
    $sid = ensure_section($conn, PR_T, 'Practice Sec');
    $conn->query("INSERT INTO section_students (section_id, student_email) VALUES ($sid, '" . PR_S . "')");
    $quiz = make_quiz($conn, [
        ['mcq', 'Capital of France?', 2, null, ['Paris' => true, 'Rome' => false]],
        ['short_answer', 'Explain', 3, null, []],
    ], ['teacher' => PR_T, 'max_attempts' => 1, 'time_limit' => 10] + $quiz_settings);
    $conn->query("UPDATE questions SET explanation = 'Paris is the capital.' WHERE quiz_id = {$quiz['quiz_id']} AND type = 'mcq'");
    return [$conn, $quiz];
}

function pr_quiz(mysqli $conn, int $id): array
{
    return $conn->query("SELECT * FROM quizzes WHERE id = $id")->fetch_assoc();
}

test('practice is allowed only for practice quizzes or released official quizzes', function () {
    [$conn, $quiz] = pr_fixture();
    $id = $quiz['quiz_id'];
    assert_contains('not turned on practice', (string) practice_block_reason($conn, pr_quiz($conn, $id), PR_S));

    $conn->query("UPDATE quizzes SET practice_enabled = 1, results_release = 'manual' WHERE id = $id");
    assert_contains('released', (string) practice_block_reason($conn, pr_quiz($conn, $id), PR_S), 'answers not released yet');
    $conn->query("UPDATE quizzes SET results_released_at = NOW(), show_answers = 0 WHERE id = $id");
    assert_contains('released', (string) practice_block_reason($conn, pr_quiz($conn, $id), PR_S), 'answers hidden');
    $conn->query("UPDATE quizzes SET show_answers = 1 WHERE id = $id");
    assert_same(null, practice_block_reason($conn, pr_quiz($conn, $id), PR_S));

    $conn->query("UPDATE quizzes SET mode = 'practice', practice_enabled = 0, results_release = 'manual', results_released_at = NULL WHERE id = $id");
    assert_same(null, practice_block_reason($conn, pr_quiz($conn, $id), PR_S), 'practice quizzes are always open for practice');
    assert_contains('not assigned', (string) practice_block_reason($conn, pr_quiz($conn, $id), 'pr-outsider@test.local'));
    $conn->query("UPDATE quizzes SET status = 'draft' WHERE id = $id");
    assert_contains('not published', (string) practice_block_reason($conn, pr_quiz($conn, $id), PR_S));
});

test('practice attempts are unlimited, untimed by default and numbered separately', function () {
    [$conn, $quiz] = pr_fixture(['status' => 'published']);
    $q = pr_quiz($conn, $quiz['quiz_id']);
    $official = start_or_resume_attempt($conn, $q, PR_S)['attempt'];
    finalize_attempt($conn, $official, []);
    assert_same('no_attempts_left', start_or_resume_attempt($conn, $q, PR_S)['error'], 'official limit reached');

    $p1 = start_or_resume_attempt($conn, $q, PR_S, '2030-01-01 00:00:00', true)['attempt'];
    assert_same(['1', '1'], [(string) $p1['is_practice'], (string) $p1['attempt_number']], 'practice #1 next to official #1');
    assert_same(null, $p1['time_limit_minutes'], 'untimed unless asked');
    assert_same(null, $p1['due_at'], 'practice has no deadline');
    finalize_attempt($conn, $p1, []);
    $p2 = start_or_resume_attempt($conn, $q, PR_S, null, true, true)['attempt'];
    assert_same(['2', '10'], [(string) $p2['attempt_number'], (string) $p2['time_limit_minutes']], 'timed on request');

    $summary = student_attempt_summary($conn, $quiz['quiz_id'], PR_S);
    assert_same(1, $summary['submitted'], 'practice does not count as an official attempt');
    assert_false($summary['in_progress'], 'an open practice attempt is not an official one');
});

test('practice never notifies the teacher about answers to grade', function () {
    [$conn, $quiz] = pr_fixture(['status' => 'published']);
    $q = pr_quiz($conn, $quiz['quiz_id']);
    finalize_attempt($conn, start_or_resume_attempt($conn, $q, PR_S, null, true)['attempt'], []);
    assert_same(0, (int) $conn->query("SELECT COUNT(*) c FROM notifications WHERE user_email = '" . PR_T . "'")->fetch_assoc()['c']);
    finalize_attempt($conn, start_or_resume_attempt($conn, $q, PR_S)['attempt'], []);
    assert_same(1, (int) $conn->query("SELECT COUNT(*) c FROM notifications WHERE user_email = '" . PR_T . "'")->fetch_assoc()['c'], 'official attempts do');
});

test('check_practice_answer() gives instant feedback, only on practice attempts', function () {
    [$conn, $quiz] = pr_fixture(['status' => 'published']);
    $q = pr_quiz($conn, $quiz['quiz_id']);
    [$mcq, $short] = $quiz['questions'];
    $practice = start_or_resume_attempt($conn, $q, PR_S, null, true)['attempt'];

    $right = check_practice_answer($conn, $practice, $mcq['id'], (string) $mcq['options']['Paris']);
    assert_same(true, $right['correct']);
    assert_same('Paris is the capital.', $right['explanation']);
    $wrong = check_practice_answer($conn, $practice, $mcq['id'], (string) $mcq['options']['Rome']);
    assert_same([false, 'Paris'], [$wrong['correct'], $wrong['answer']]);
    assert_same(null, check_practice_answer($conn, $practice, $short['id'], 'Because')['correct'], 'hand-graded: no verdict');
    assert_same(null, check_practice_answer($conn, $practice, 999999, 'x'), 'not a question of this attempt');

    $official = start_or_resume_attempt($conn, $q, PR_S)['attempt'];
    assert_same(null, check_practice_answer($conn, $official, $mcq['id'], (string) $mcq['options']['Paris']), 'never on an official attempt');
});

test('bookmarks: toggle, notes, privacy and when the answer key shows', function () {
    [$conn, $quiz] = pr_fixture(['status' => 'published']);
    $conn->query("UPDATE quizzes SET results_release = 'manual' WHERE id = {$quiz['quiz_id']}");
    $q = pr_quiz($conn, $quiz['quiz_id']);
    [$mcq] = $quiz['questions'];
    $official = start_or_resume_attempt($conn, $q, PR_S)['attempt'];
    finalize_attempt($conn, $official, []);

    assert_true(toggle_bookmark($conn, PR_S, (int) $official['id'], $mcq['id']));
    $marks = student_bookmarks($conn, PR_S);
    assert_same(1, count($marks));
    assert_false($marks[0]['show_key'], 'results not released: no answer key');
    assert_same('Capital of France?', $marks[0]['row']['question_text']);

    $conn->query("UPDATE quizzes SET results_released_at = NOW() WHERE id = {$quiz['quiz_id']}");
    assert_true(student_bookmarks($conn, PR_S)[0]['show_key']);

    update_bookmark_note($conn, PR_S, $marks[0]['id'], ' Remember Paris ');
    update_bookmark_note($conn, 'pr-outsider@test.local', $marks[0]['id'], 'hijack');
    assert_same('Remember Paris', student_bookmarks($conn, PR_S)[0]['note']);
    assert_false(delete_bookmark($conn, 'pr-outsider@test.local', $marks[0]['id']));
    assert_same([], student_bookmarks($conn, 'pr-outsider@test.local'));

    try {
        toggle_bookmark($conn, 'pr-outsider@test.local', (int) $official['id'], $mcq['id']);
        fail('cannot bookmark in someone else\'s attempt');
    } catch (InvalidArgumentException $e) {
        assert_contains('not found', $e->getMessage());
    }
    assert_false(toggle_bookmark($conn, PR_S, (int) $official['id'], $mcq['id']), 'second toggle removes it');
    assert_same([], student_bookmarks($conn, PR_S));
});
