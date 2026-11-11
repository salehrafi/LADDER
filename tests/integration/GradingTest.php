<?php
// Answer sanitising and grading against a real (isolated) MySQL database.

function grading_fixture(): array
{
    reset_test_db();
    $conn = test_db();
    $quiz = make_quiz($conn, [
        ['mcq', 'Capital of Bangladesh?', 2, null, ['Chittagong' => false, 'Dhaka' => true, 'Sylhet' => false]],
        ['short_answer', 'Process plants use to make food?', 3, 'Photosynthesis', []],
        ['short_answer', 'Explain osmosis.', 5, null, []],
    ]);
    return [$conn, $quiz, make_attempt($conn, $quiz)];
}

function answer_rows(mysqli $conn, int $attempt_id): array
{
    return $conn->query("SELECT * FROM attempt_answers WHERE attempt_id = $attempt_id ORDER BY question_id")->fetch_all(MYSQLI_ASSOC);
}

test('sanitize_answers() keeps only valid answers for this attempt', function () {
    [$conn, $quiz, $attempt] = grading_fixture();
    [$mcq, $short] = $quiz['questions'];

    $other = make_quiz($conn, [['mcq', 'Other', 1, null, ['x' => true, 'y' => false]]]);
    $foreign_option = $other['questions'][0]['correct'];

    $clean = sanitize_answers($conn, $attempt, [
        'q_' . $mcq['id'] => (string) $foreign_option,   // option from another question
        'q_' . $short['id'] => '  Photosynthesis  ',
        'q_999999' => 'unknown question',
        'csrf_token' => 'ignored',
    ]);

    assert_same(['q_' . $short['id'] => 'Photosynthesis'], $clean);

    $clean = sanitize_answers($conn, $attempt, ['q_' . $mcq['id'] => (string) $mcq['correct']]);
    assert_same(['q_' . $mcq['id'] => (string) $mcq['correct']], $clean);
});

test('sanitize_answers() rejects array input and truncates long text', function () {
    [$conn, $quiz, $attempt] = grading_fixture();
    [$mcq, $short] = $quiz['questions'];

    $clean = sanitize_answers($conn, $attempt, [
        'q_' . $mcq['id'] => ['1', '2'],
        'q_' . $short['id'] => str_repeat('a', SHORT_ANSWER_MAX_LENGTH + 100),
    ]);
    assert_same(false, isset($clean['q_' . $mcq['id']]));
    assert_same(SHORT_ANSWER_MAX_LENGTH, mb_strlen($clean['q_' . $short['id']]));
});

test('finalize_attempt() grades MCQ and auto-gradable short answers', function () {
    [$conn, $quiz, $attempt] = grading_fixture();
    [$mcq, $short, $essay] = $quiz['questions'];

    $ok = finalize_attempt($conn, $attempt, [
        'q_' . $mcq['id'] => (string) $mcq['correct'],
        'q_' . $short['id'] => 'PHOTOSYNTHESIS',
        'q_' . $essay['id'] => 'Water moves across a membrane.',
    ]);
    assert_true($ok);

    $row = $conn->query("SELECT * FROM quiz_attempts WHERE id = {$attempt['id']}")->fetch_assoc();
    assert_same('submitted', $row['status']);
    assert_same('5.00', $row['total_score'], 'MCQ 2 + short 3');
    assert_same('10.00', $row['max_score']);
    assert_same('1', (string) $row['needs_manual_grading'], 'essay without model answer is pending');
    assert_same(null, $row['draft_answers']);

    $answers = answer_rows($conn, (int) $attempt['id']);
    assert_same(3, count($answers));
    assert_same(null, $answers[2]['points_awarded'], 'essay waits for the teacher');
});

test('finalize_attempt() scores wrong and unanswered questions as zero', function () {
    [$conn, $quiz, $attempt] = grading_fixture();
    [$mcq] = $quiz['questions'];
    $wrong = $mcq['options']['Sylhet'];

    finalize_attempt($conn, $attempt, ['q_' . $mcq['id'] => (string) $wrong]);

    $answers = answer_rows($conn, (int) $attempt['id']);
    assert_same('0', (string) $answers[0]['is_correct']);
    assert_same('0.00', $answers[0]['points_awarded']);
    assert_same('0.00', $answers[1]['points_awarded'], 'blank short answer with a model answer scores 0');
});

test('finalize_attempt() is idempotent: a second submit is ignored', function () {
    [$conn, $quiz, $attempt] = grading_fixture();
    [$mcq] = $quiz['questions'];

    assert_true(finalize_attempt($conn, $attempt, ['q_' . $mcq['id'] => (string) $mcq['correct']]));
    assert_false(finalize_attempt($conn, $attempt, []), 'second call must not re-grade');

    assert_same(3, count(answer_rows($conn, (int) $attempt['id'])), 'no duplicate answer rows');
    $row = $conn->query("SELECT total_score FROM quiz_attempts WHERE id = {$attempt['id']}")->fetch_assoc();
    assert_same('2.00', $row['total_score'], 'score from the first submit is kept');
});

test('save_draft() only writes to in-progress attempts', function () {
    [$conn, $quiz, $attempt] = grading_fixture();
    $id = (int) $attempt['id'];

    save_draft($conn, $id, ['q_1' => 'draft']);
    $row = $conn->query("SELECT draft_answers FROM quiz_attempts WHERE id = $id")->fetch_assoc();
    assert_same(['q_1' => 'draft'], json_decode($row['draft_answers'], true));

    finalize_attempt($conn, $attempt, []);
    save_draft($conn, $id, ['q_1' => 'late']);
    $row = $conn->query("SELECT draft_answers FROM quiz_attempts WHERE id = $id")->fetch_assoc();
    assert_same(null, $row['draft_answers']);
});

test('load_attempt_for_student() enforces owner and status', function () {
    [$conn, $quiz, $attempt] = grading_fixture();
    $id = (int) $attempt['id'];

    assert_true(load_attempt_for_student($conn, $id, 'student@test.local') !== null);
    assert_same(null, load_attempt_for_student($conn, $id, 'someone-else@test.local'));
    assert_same(null, load_attempt_for_student($conn, $id, 'student@test.local', 'submitted'));
});

test('finalize_attempt() records why the attempt was closed', function () {
    [$conn, $quiz, $attempt] = grading_fixture();
    finalize_attempt($conn, $attempt, [], 'time_up');
    $row = $conn->query("SELECT submit_reason FROM quiz_attempts WHERE id = {$attempt['id']}")->fetch_assoc();
    assert_same('time_up', $row['submit_reason']);

    $second = make_attempt($conn, $quiz, 'other@test.local');
    finalize_attempt($conn, $second, [], 'not-a-reason');
    $row = $conn->query("SELECT submit_reason FROM quiz_attempts WHERE id = {$second['id']}")->fetch_assoc();
    assert_same('manual', $row['submit_reason'], 'unknown reasons fall back to manual');
});

test('submit_on_leave() grades the beacon answers with reason left_page', function () {
    [$conn, $quiz, $attempt] = grading_fixture();
    [$mcq] = $quiz['questions'];

    assert_true(submit_on_leave($conn, $attempt, ['q_' . $mcq['id'] => (string) $mcq['correct']]));
    $row = $conn->query("SELECT status, submit_reason, total_score FROM quiz_attempts WHERE id = {$attempt['id']}")->fetch_assoc();
    assert_same('submitted', $row['status']);
    assert_same('left_page', $row['submit_reason']);
    assert_same('2.00', $row['total_score']);

    assert_false(submit_on_leave($conn, $attempt, []), 'already closed');
});

test('submit_on_leave() falls back to the autosave when the beacon is empty', function () {
    [$conn, $quiz, $attempt] = grading_fixture();
    [$mcq] = $quiz['questions'];
    save_draft($conn, (int) $attempt['id'], ['q_' . $mcq['id'] => (string) $mcq['correct']]);
    $attempt = $conn->query("SELECT * FROM quiz_attempts WHERE id = {$attempt['id']}")->fetch_assoc();

    submit_on_leave($conn, $attempt, []);
    $row = $conn->query("SELECT total_score FROM quiz_attempts WHERE id = {$attempt['id']}")->fetch_assoc();
    assert_same('2.00', $row['total_score']);
});

test('submit_on_leave() after the deadline uses the autosave and reason time_up', function () {
    reset_test_db();
    $conn = test_db();
    $quiz = make_quiz($conn, [['mcq', 'Q', 1, null, ['a' => true, 'b' => false]]]);
    $attempt = make_attempt($conn, $quiz, 'student@test.local', 5, 30); // 5 min limit, started 30 min ago
    $q = $quiz['questions'][0];

    submit_on_leave($conn, $attempt, ['q_' . $q['id'] => (string) $q['correct']]);
    $row = $conn->query("SELECT submit_reason, total_score FROM quiz_attempts WHERE id = {$attempt['id']}")->fetch_assoc();
    assert_same('time_up', $row['submit_reason']);
    assert_same('0.00', $row['total_score'], 'late answers are ignored; draft was empty');
});

test('submit_reason_label() describes each reason', function () {
    assert_contains('left the quiz page', submit_reason_label('left_page'));
    assert_contains('time ran out', submit_reason_label('time_up'));
    assert_same('', submit_reason_label(null));
});
