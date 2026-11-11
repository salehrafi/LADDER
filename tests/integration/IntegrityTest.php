<?php
// Phase 1: question snapshots and historical results, soft-deleted questions,
// attempt start races, grade clamping, results release, foreign keys, throttling.
// Runs on the isolated test database.
require_once APP_ROOT . '/includes/class_helpers.php';

function integrity_quiz(mysqli $conn, array $settings = []): array
{
    reset_test_db();
    ensure_test_account($conn, 'student@test.local', 'student');
    return make_quiz($conn, [
        ['mcq', 'Capital of France?', 2, null, ['Paris' => true, 'Rome' => false]],
        ['short_answer', 'Opposite of hot?', 3, 'cold', []],
    ], $settings);
}

function quiz_row(mysqli $conn, int $quiz_id): array
{
    return $conn->query("SELECT * FROM quizzes WHERE id = $quiz_id")->fetch_assoc();
}

test('starting an attempt snapshots questions, options and answer keys', function () {
    $conn = test_db();
    $quiz = integrity_quiz($conn);
    $r = start_or_resume_attempt($conn, quiz_row($conn, $quiz['quiz_id']), 'student@test.local');
    assert_same(null, $r['error']);

    $snap = load_attempt_snapshot($conn, $r['attempt']);
    assert_same(2, count($snap));
    $mcq = $snap[$quiz['questions'][0]['id']];
    assert_same('Capital of France?', $mcq['question_text']);
    assert_same(2.0, $mcq['points']);
    assert_same(2, count($mcq['options']));
    assert_same('cold', $snap[$quiz['questions'][1]['id']]['model_answer']);
    assert_false($mcq['backfilled']);
});

test('editing questions after an attempt started does not change its grading or review', function () {
    $conn = test_db();
    $quiz = integrity_quiz($conn);
    $attempt = start_or_resume_attempt($conn, quiz_row($conn, $quiz['quiz_id']), 'student@test.local')['attempt'];
    [$mcq, $short] = $quiz['questions'];

    // The teacher changes everything: text, points, the correct option and the key.
    $conn->query("UPDATE questions SET question_text = 'CHANGED', points = 10 WHERE quiz_id = {$quiz['quiz_id']}");
    $conn->query("UPDATE question_options SET is_correct = 1 - is_correct WHERE question_id = {$mcq['id']}");
    $conn->query("UPDATE questions SET model_answer = 'warm' WHERE id = {$short['id']}");

    assert_true(finalize_attempt($conn, $attempt, ['q_' . $mcq['id'] => (string) $mcq['options']['Paris'], 'q_' . $short['id'] => 'Cold']));
    $row = $conn->query("SELECT total_score, max_score FROM quiz_attempts WHERE id = {$attempt['id']}")->fetch_assoc();
    assert_same('5.00', $row['total_score'], 'graded with the original key and points');
    assert_same('5.00', $row['max_score']);

    $review = attempt_review_rows($conn, $attempt);
    assert_same('Capital of France?', $review[0]['question_text']);
    assert_same(2.0, $review[0]['question_points']);
    $correct = array_values(array_filter($review[0]['options'], fn($o) => $o['is_correct']));
    assert_same('Paris', $correct[0]['option_text']);
});

test('a deleted question leaves new attempts but stays in past ones', function () {
    $conn = test_db();
    $quiz = integrity_quiz($conn, ['max_attempts' => 0]);
    $old = start_or_resume_attempt($conn, quiz_row($conn, $quiz['quiz_id']), 'student@test.local')['attempt'];
    finalize_attempt($conn, $old, []);

    $short = $quiz['questions'][1]['id'];
    $conn->query("UPDATE questions SET deleted_at = NOW() WHERE id = $short");

    $new = start_or_resume_attempt($conn, quiz_row($conn, $quiz['quiz_id']), 'student@test.local')['attempt'];
    assert_same([$quiz['questions'][0]['id']], array_keys(load_attempt_snapshot($conn, $new)));
    assert_same(2, count(attempt_review_rows($conn, $old)), 'old attempt still shows both answers');
});

test('start_or_resume_attempt() resumes, enforces the limit and needs questions', function () {
    $conn = test_db();
    $quiz = integrity_quiz($conn, ['max_attempts' => 1]);
    $q = quiz_row($conn, $quiz['quiz_id']);

    $first = start_or_resume_attempt($conn, $q, 'student@test.local')['attempt'];
    $again = start_or_resume_attempt($conn, $q, 'student@test.local')['attempt'];
    assert_same($first['id'], $again['id'], 'a second Start resumes the same attempt');
    assert_same(1, (int) $conn->query("SELECT COUNT(*) c FROM quiz_attempts")->fetch_assoc()['c']);

    finalize_attempt($conn, $first, []);
    assert_same('no_attempts_left', start_or_resume_attempt($conn, $q, 'student@test.local')['error']);

    $empty = make_quiz($conn, [], ['title' => 'Empty']);
    assert_same('no_questions', start_or_resume_attempt($conn, quiz_row($conn, $empty['quiz_id']), 'student@test.local')['error']);
});

test('the database refuses a duplicate attempt number', function () {
    $conn = test_db();
    $quiz = integrity_quiz($conn);
    make_attempt($conn, $quiz);
    try {
        make_attempt($conn, $quiz); // same student, attempt_number 1 again
        fail('expected a duplicate-key error');
    } catch (mysqli_sql_exception $e) {
        assert_same(1062, $e->getCode());
    }
});

test('attempts from before snapshots are backfilled and flagged', function () {
    $conn = test_db();
    $quiz = integrity_quiz($conn);
    $attempt = make_attempt($conn, $quiz); // inserted directly, no snapshot
    $conn->query("UPDATE quiz_attempts SET status = 'submitted' WHERE id = {$attempt['id']}");
    $attempt['status'] = 'submitted';

    $snap = load_attempt_snapshot($conn, $attempt);
    assert_same(2, count($snap));
    assert_true(reset($snap)['backfilled']);
});

test('teacher grades are clamped to the points the question had when taken', function () {
    $conn = test_db();
    reset_test_db();
    ensure_test_account($conn, 'student@test.local', 'student');
    $quiz = make_quiz($conn, [['short_answer', 'Explain', 3, null, []]]);
    $attempt = start_or_resume_attempt($conn, quiz_row($conn, $quiz['quiz_id']), 'student@test.local')['attempt'];
    finalize_attempt($conn, $attempt, ['q_' . $quiz['questions'][0]['id'] => 'An answer']);
    $conn->query("UPDATE questions SET points = 50"); // raised after the attempt

    $answer_id = (int) $conn->query("SELECT id FROM attempt_answers WHERE attempt_id = {$attempt['id']}")->fetch_assoc()['id'];
    $pending = save_teacher_grades($conn, $attempt, 'teacher@test.local', [$answer_id => '40'], [$answer_id => 'Nice']);
    assert_false($pending);
    $row = $conn->query("SELECT total_score, needs_manual_grading FROM quiz_attempts WHERE id = {$attempt['id']}")->fetch_assoc();
    assert_same('3.00', $row['total_score'], 'clamped to the original 3 points');
    assert_same('0', (string) $row['needs_manual_grading']);
});

test('results_released() and results_released_sql() agree', function () {
    $conn = test_db();
    $quiz = integrity_quiz($conn);
    $id = $quiz['quiz_id'];
    $past = date('Y-m-d H:i:s', time() - 3600);
    $future = date('Y-m-d H:i:s', time() + 3600);
    $cases = [
        ['immediate', 'published', null, null, true],
        ['manual', 'published', null, null, false],
        ['manual', 'closed', null, null, false],
        ['manual', 'published', null, $past, true],
        ['after_close', 'published', null, null, false],
        ['after_close', 'published', $future, null, false],
        ['after_close', 'published', $past, null, true],
        ['after_close', 'closed', null, null, true],
    ];
    foreach ($cases as [$mode, $status, $end, $released_at, $expected]) {
        $stmt = $conn->prepare("UPDATE quizzes SET results_release = ?, status = ?, end_time = ?, results_released_at = ? WHERE id = ?");
        $stmt->bind_param("ssssi", $mode, $status, $end, $released_at, $id);
        $stmt->execute();
        $stmt->close();
        $label = "$mode/$status/end=" . ($end ?? '-') . "/released=" . ($released_at ?? '-');
        assert_same($expected, results_released(quiz_row($conn, $id)), "PHP: $label");
        $sql = (int) $conn->query("SELECT " . results_released_sql('q') . " AS r FROM quizzes q WHERE q.id = $id")->fetch_assoc()['r'] === 1;
        assert_same($expected, $sql, "SQL: $label");
    }
});

test('answers_visible() needs released results and show_answers', function () {
    assert_true(answers_visible(['results_release' => 'immediate', 'show_answers' => 1]));
    assert_false(answers_visible(['results_release' => 'immediate', 'show_answers' => 0]));
    assert_false(answers_visible(['results_release' => 'manual', 'show_answers' => 1]));
});

test('an email change cascades to attempts and enrolments; accounts with results cannot be deleted', function () {
    $conn = test_db();
    $quiz = integrity_quiz($conn);
    $attempt = make_attempt($conn, $quiz, 'before@test.local');
    $sid = ensure_section($conn, 'teacher@test.local', 'Sec FK');
    $conn->query("INSERT INTO section_students (section_id, student_email) VALUES ($sid, 'before@test.local')");

    $conn->query("UPDATE reg_info SET email = 'after@test.local' WHERE email = 'before@test.local'");
    assert_same('after@test.local', $conn->query("SELECT student_email FROM quiz_attempts WHERE id = {$attempt['id']}")->fetch_assoc()['student_email']);
    assert_same(1, (int) $conn->query("SELECT COUNT(*) c FROM section_students WHERE student_email = 'after@test.local'")->fetch_assoc()['c']);

    try {
        $conn->query("DELETE FROM reg_info WHERE email = 'after@test.local'");
        fail('deleting a student who has attempts must be refused');
    } catch (mysqli_sql_exception $e) {
        assert_same(1451, $e->getCode());
    }
});

test('a password reset by the teacher ends the student\'s sessions', function () {
    $conn = test_db();
    reset_test_db();
    ensure_test_account($conn, 'teacher@test.local', 'teacher');
    ensure_test_account($conn, 'kid@test.local', 'student');
    $sid = ensure_section($conn, 'teacher@test.local', 'Sec R');
    $conn->query("INSERT INTO section_students (section_id, student_email) VALUES ($sid, 'kid@test.local')");
    $teacher_id = (int) $conn->query("SELECT user_id FROM reg_info WHERE email = 'teacher@test.local'")->fetch_assoc()['user_id'];
    $conn->query("UPDATE reg_info SET managed_by = $teacher_id WHERE email = 'kid@test.local'"); // the teacher created this account
    $before =(int) $conn->query("SELECT auth_version v FROM reg_info WHERE email = 'kid@test.local'")->fetch_assoc()['v'];

    assert_true(reset_student_password($conn, 'teacher@test.local', 'kid@test.local', 'Fresh@1234'));
    $after = (int) $conn->query("SELECT auth_version v FROM reg_info WHERE email = 'kid@test.local'")->fetch_assoc()['v'];
    assert_same($before + 1, $after);
});

test('throttle_blocked() trips at the limit and throttle_clear() resets it', function () {
    $conn = test_db();
    reset_test_db();
    [$max] = THROTTLE_RULES['login_email'];
    for ($i = 0; $i < $max - 1; $i++) {
        throttle_record($conn, 'login_email', 'Who@Test.Local');
    }
    assert_false(throttle_blocked($conn, 'login_email', 'who@test.local'), 'one below the limit');
    throttle_record($conn, 'login_email', 'who@test.local');
    assert_true(throttle_blocked($conn, 'login_email', 'WHO@test.local'), 'case-insensitive subject');
    assert_false(throttle_blocked($conn, 'login_email', 'other@test.local'));
    throttle_clear($conn, 'login_email', 'who@test.local');
    assert_false(throttle_blocked($conn, 'login_email', 'who@test.local'));
});

test('a reset code is stored hashed, works once, and expires', function () {
    $conn = test_db();
    reset_test_db();
    ensure_test_account($conn, 'reset@test.local', 'student');

    $code = issue_password_reset_code($conn, 'reset@test.local');
    assert_true((bool) preg_match('/^\d{6}$/', (string) $code), 'six digits');
    $stored = $conn->query("SELECT otp FROM reg_info WHERE email = 'reset@test.local'")->fetch_assoc()['otp'];
    assert_true($stored !== $code && password_verify($code, $stored), 'only a hash is stored');

    assert_true(verify_password_reset_code($conn, 'reset@test.local', $code));
    assert_false(verify_password_reset_code($conn, 'reset@test.local', $code), 'used up');

    $code = issue_password_reset_code($conn, 'reset@test.local');
    $conn->query("UPDATE reg_info SET exp_time = NOW() - INTERVAL 1 MINUTE WHERE email = 'reset@test.local'");
    assert_false(verify_password_reset_code($conn, 'reset@test.local', $code), 'expired');

    assert_same(null, issue_password_reset_code($conn, 'nobody@test.local'), 'no code for unknown accounts');
});

test('five wrong reset codes void the code', function () {
    $conn = test_db();
    reset_test_db();
    ensure_test_account($conn, 'reset@test.local', 'student');
    $code = issue_password_reset_code($conn, 'reset@test.local');
    $wrong = $code === '000000' ? '111111' : '000000';
    for ($i = 0; $i < OTP_MAX_ATTEMPTS - 1; $i++) {
        assert_false(verify_password_reset_code($conn, 'reset@test.local', $wrong));
    }
    $row = $conn->query("SELECT otp FROM reg_info WHERE email = 'reset@test.local'")->fetch_assoc();
    assert_true($row['otp'] !== null, 'still usable after ' . (OTP_MAX_ATTEMPTS - 1) . ' wrong tries');
    assert_false(verify_password_reset_code($conn, 'reset@test.local', $wrong));
    assert_false(verify_password_reset_code($conn, 'reset@test.local', $code), 'the right code no longer works');
});
