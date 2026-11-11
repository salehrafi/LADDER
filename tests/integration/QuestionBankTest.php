<?php
// Question bank, subjects/topics, the question form, new question types end
// to end, re-grading and the grade audit. Runs on the isolated test database.
require_once APP_ROOT . '/includes/question_bank.php';
require_once APP_ROOT . '/includes/class_helpers.php';

const QB_T1 = 'qb-t1@test.local';
const QB_T2 = 'qb-t2@test.local';

function qb_fixture(): mysqli
{
    reset_test_db();
    $conn = test_db();
    foreach (['grade_audit', 'topics', 'subjects'] as $t) {
        $conn->query("SET FOREIGN_KEY_CHECKS = 0");
        $conn->query("TRUNCATE TABLE `$t`");
        $conn->query("SET FOREIGN_KEY_CHECKS = 1");
    }
    ensure_test_account($conn, QB_T1, 'teacher');
    ensure_test_account($conn, QB_T2, 'teacher');
    ensure_test_account($conn, 'qb-s@test.local', 'student');
    return $conn;
}

function qb_save(mysqli $conn, array $post, ?int $quiz_id = null, string $teacher = QB_T1): int
{
    [$data, $errors] = question_from_form($conn, $teacher, $post);
    assert_same([], $errors, 'form should be valid');
    return save_question($conn, $teacher, $data, null, $quiz_id);
}

test('subjects and topics belong to their teacher', function () {
    $conn = qb_fixture();
    $bio = create_subject($conn, QB_T1, 'Biology');
    assert_same($bio, create_subject($conn, QB_T1, ' Biology '), 'same name returns the existing subject');
    $cells = create_topic($conn, QB_T1, $bio, 'Cells');
    assert_true(teacher_owns_topic($conn, QB_T1, $cells));
    assert_false(teacher_owns_topic($conn, QB_T2, $cells));
    try {
        create_topic($conn, QB_T2, $bio, 'Sneaky');
        fail('another teacher cannot add topics to my subject');
    } catch (InvalidArgumentException $e) {
        assert_contains('your subjects', $e->getMessage());
    }
    $catalog = teacher_catalog($conn, QB_T1);
    assert_same('Cells', $catalog[$bio]['topics'][0]['name']);
    assert_same([], teacher_catalog($conn, QB_T2));
});

test('question_from_form() validates each type', function () {
    $conn = qb_fixture();
    $bad = [
        [['type' => 'mcq', 'question_text' => 'Q', 'points' => '1', 'options' => ['a', 'b']], 'Select which option is correct.'],
        [['type' => 'mcq_multi', 'question_text' => 'Q', 'points' => '1', 'options' => ['a', 'b']], 'Tick at least one correct option.'],
        [['type' => 'numeric', 'question_text' => 'Q', 'points' => '1', 'numeric_value' => 'x'], 'Enter the correct numerical answer.'],
        [['type' => 'fill_blank', 'question_text' => 'No blanks here', 'points' => '1', 'blank_answers' => 'a'], 'Mark each blank'],
        [['type' => 'fill_blank', 'question_text' => '___ and ___', 'points' => '1', 'blank_answers' => 'a'], '2 blank(s)'],
        [['type' => 'essay', 'question_text' => '', 'points' => '1'], 'Question text is required.'],
        [['type' => 'mcq', 'question_text' => 'Q', 'points' => '5000', 'options' => ['a', 'b'], 'correct_option' => '0'], 'Points must be'],
        [['type' => 'mcq', 'question_text' => 'Q', 'points' => '1', 'options' => ['a', 'b'], 'correct_option' => '0', 'topic_id' => '999'], 'Choose one of your topics.'],
    ];
    foreach ($bad as [$post, $message]) {
        [$data, $errors] = question_from_form($conn, QB_T1, $post);
        assert_same(null, $data);
        assert_contains($message, implode(' ', $errors));
    }
    [$data] = question_from_form($conn, QB_T1, ['type' => 'true_false', 'question_text' => 'Sky is blue', 'points' => '1', 'tf_answer' => 'false']);
    assert_same([['text' => 'True', 'correct' => false], ['text' => 'False', 'correct' => true]], $data['options']);
});

test('editing a question keeps its option ids', function () {
    $conn = qb_fixture();
    $id = qb_save($conn, ['type' => 'mcq', 'question_text' => 'Q', 'points' => '1', 'options' => ['a', 'b', 'c'], 'correct_option' => '0']);
    $before = array_column(find_teacher_question($conn, QB_T1, $id)['options'], 'id');

    [$data] = question_from_form($conn, QB_T1, ['type' => 'mcq', 'question_text' => 'Q2', 'points' => '2', 'options' => ['a', 'B!'], 'correct_option' => '1']);
    save_question($conn, QB_T1, $data, $id);
    $q = find_teacher_question($conn, QB_T1, $id);
    assert_same('Q2', $q['question_text']);
    assert_same(array_slice($before, 0, 2), array_column($q['options'], 'id'), 'kept ids, surplus removed');
    assert_same(['0', '1'], array_column($q['options'], 'is_correct'));
    assert_same(null, find_teacher_question($conn, QB_T2, $id), "another teacher cannot load it");
});

test('bank questions are copied into quizzes and back, independently', function () {
    $conn = qb_fixture();
    $quiz = make_quiz($conn, [], ['teacher' => QB_T1]);
    $bank_id = qb_save($conn, ['type' => 'short_answer', 'question_text' => 'Capital of France?', 'points' => '2', 'accepted' => "Paris\nparis city"]);

    $copy_id = copy_question($conn, QB_T1, $bank_id, $quiz['quiz_id']);
    $copy = find_teacher_question($conn, QB_T1, $copy_id);
    assert_same((string) $quiz['quiz_id'], (string) $copy['quiz_id']);
    assert_same((string) $bank_id, (string) $copy['source_question_id']);

    [$data] = question_from_form($conn, QB_T1, ['type' => 'short_answer', 'question_text' => 'Changed in bank', 'points' => '2', 'accepted' => 'Paris']);
    save_question($conn, QB_T1, $data, $bank_id);
    assert_same('Capital of France?', find_teacher_question($conn, QB_T1, $copy_id)['question_text'], 'the quiz copy is unchanged');

    $back = copy_question($conn, QB_T1, $copy_id, null);
    assert_same(null, find_teacher_question($conn, QB_T1, $back)['quiz_id'], 'saved to the bank');
    try {
        copy_question($conn, QB_T2, $bank_id, null);
        fail('another teacher cannot copy my question');
    } catch (InvalidArgumentException $e) {
        assert_contains('not found', $e->getMessage());
    }
});

test('bank_questions() filters by text, topic, type and difficulty, and pages', function () {
    $conn = qb_fixture();
    $sub = create_subject($conn, QB_T1, 'Maths');
    $alg = create_topic($conn, QB_T1, $sub, 'Algebra');
    for ($i = 1; $i <= 25; $i++) {
        qb_save($conn, ['type' => 'numeric', 'question_text' => "Solve x + $i = 0", 'points' => '1', 'numeric_value' => (string) -$i,
            'difficulty' => $i <= 5 ? 'hard' : 'easy', 'topic_id' => $i <= 10 ? (string) $alg : '0']);
    }
    qb_save($conn, ['type' => 'essay', 'question_text' => 'Explain fractions', 'points' => '5']);
    qb_save($conn, ['type' => 'essay', 'question_text' => 'Not mine', 'points' => '5'], null, QB_T2);

    assert_same(26, bank_questions($conn, QB_T1, [])['total']);
    assert_same(20, count(bank_questions($conn, QB_T1, [])['rows']), 'first page');
    assert_same(6, count(bank_questions($conn, QB_T1, [], 2)['rows']));
    assert_same(10, bank_questions($conn, QB_T1, ['topic_id' => $alg])['total']);
    assert_same(10, bank_questions($conn, QB_T1, ['subject_id' => $sub])['total']);
    assert_same(5, bank_questions($conn, QB_T1, ['difficulty' => 'hard'])['total']);
    assert_same(1, bank_questions($conn, QB_T1, ['type' => 'essay'])['total']);
    assert_same(1, bank_questions($conn, QB_T1, ['q' => 'fractions'])['total']);
    assert_same(0, bank_questions($conn, QB_T1, ['q' => '100%'])['total'], 'LIKE wildcards are escaped');
});

test('remove_question() hides an answered question and deletes an unused one', function () {
    $conn = qb_fixture();
    $quiz = make_quiz($conn, [], ['teacher' => QB_T1]);
    $used = qb_save($conn, ['type' => 'true_false', 'question_text' => 'T?', 'points' => '1', 'tf_answer' => 'true'], $quiz['quiz_id']);
    $unused = qb_save($conn, ['type' => 'essay', 'question_text' => 'E?', 'points' => '1']);
    $q = $conn->query("SELECT * FROM quizzes WHERE id = {$quiz['quiz_id']}")->fetch_assoc();
    start_or_resume_attempt($conn, $q, 'qb-s@test.local');

    assert_same('hidden', remove_question($conn, QB_T1, $used));
    assert_same('deleted', remove_question($conn, QB_T1, $unused));
    assert_same('not_found', remove_question($conn, QB_T2, $used));
    assert_true($conn->query("SELECT deleted_at FROM questions WHERE id = $used")->fetch_assoc()['deleted_at'] !== null);
});

test('a quiz with every question type grades end to end from the snapshot', function () {
    $conn = qb_fixture();
    $quiz = make_quiz($conn, [], ['teacher' => QB_T1]);
    $qid = $quiz['quiz_id'];
    $ids = [
        'tf' => qb_save($conn, ['type' => 'true_false', 'question_text' => 'Water is wet', 'points' => '1', 'tf_answer' => 'true'], $qid),
        'multi' => qb_save($conn, ['type' => 'mcq_multi', 'question_text' => 'Primes', 'points' => '2', 'options' => ['2', '3', '4'], 'correct' => ['0', '1'], 'scoring' => 'partial'], $qid),
        'num' => qb_save($conn, ['type' => 'numeric', 'question_text' => 'g?', 'points' => '2', 'numeric_value' => '9.81', 'tolerance' => '0.1'], $qid),
        'blank' => qb_save($conn, ['type' => 'fill_blank', 'question_text' => '___ is in ___', 'points' => '2', 'blank_answers' => "Paris\nFrance", 'partial' => '1', 'ignore_punctuation' => '1'], $qid),
        'short' => qb_save($conn, ['type' => 'short_answer', 'question_text' => 'Gas?', 'points' => '1', 'accepted' => "Oxygen\nO2", 'ignore_punctuation' => '1'], $qid),
        'essay' => qb_save($conn, ['type' => 'essay', 'question_text' => 'Discuss', 'points' => '5'], $qid),
    ];
    $q = $conn->query("SELECT * FROM quizzes WHERE id = $qid")->fetch_assoc();
    $attempt = start_or_resume_attempt($conn, $q, 'qb-s@test.local')['attempt'];
    $snap = load_attempt_snapshot($conn, $attempt);
    $opt = fn($key, $i) => (string) $snap[$ids[$key]]['options'][$i]['id'];
    // Option order may be shuffled per attempt; look options up by text.
    $by_text = function ($key, $text) use ($snap, $ids) {
        foreach ($snap[$ids[$key]]['options'] as $o) {
            if ($o['text'] === $text) return (string) $o['id'];
        }
        return '';
    };

    $raw = [
        'q_' . $ids['tf'] => $by_text('tf', 'True'),
        'q_' . $ids['multi'] => [$by_text('multi', '2')],          // 1 of 2 right: partial 1 pt
        'q_' . $ids['num'] => '9.75',
        'q_' . $ids['blank'] => ['paris', 'Spain'],                 // 1 of 2: 1 pt
        'q_' . $ids['short'] => 'o2!',
        'q_' . $ids['essay'] => 'My essay',
        'q_99999' => 'ignored',
    ];
    $clean = sanitize_answers($conn, $attempt, $raw);
    assert_false(isset($clean['q_99999']));
    assert_true(finalize_attempt($conn, $attempt, $clean));

    $row = $conn->query("SELECT total_score, max_score, needs_manual_grading FROM quiz_attempts WHERE id = {$attempt['id']}")->fetch_assoc();
    assert_same('6.00', $row['total_score'], 'tf 1 + multi 1 + numeric 2 + blanks 1 + short 1');
    assert_same('13.00', $row['max_score']);
    assert_same('1', (string) $row['needs_manual_grading'], 'the essay waits for the teacher');

    $review = [];
    foreach (attempt_review_rows($conn, $attempt) as $r) {
        $review[$r['type']] = $r;
    }
    assert_same(['paris', 'Spain'], $review['fill_blank']['parts']);
    assert_same(1, count($review['mcq_multi']['parts']));
    assert_same('My essay', $review['essay']['answer_text']);
});

test('re-grading applies a corrected key, keeps manual scores and records every change', function () {
    $conn = qb_fixture();
    $quiz = make_quiz($conn, [], ['teacher' => QB_T1]);
    $qid = $quiz['quiz_id'];
    $short = qb_save($conn, ['type' => 'short_answer', 'question_text' => 'Capital?', 'points' => '2', 'accepted' => 'Lyon'], $qid);
    $num = qb_save($conn, ['type' => 'numeric', 'question_text' => 'Pi?', 'points' => '2', 'numeric_value' => '3'], $qid);
    $q = $conn->query("SELECT * FROM quizzes WHERE id = $qid")->fetch_assoc();

    ensure_test_account($conn, 'qb-s2@test.local', 'student');
    $a1 = start_or_resume_attempt($conn, $q, 'qb-s@test.local')['attempt'];
    finalize_attempt($conn, $a1, ["q_$short" => 'Paris', "q_$num" => '3.14']);
    $a2 = start_or_resume_attempt($conn, $q, 'qb-s2@test.local')['attempt'];
    finalize_attempt($conn, $a2, ["q_$short" => 'Paris', "q_$num" => '3.14']);

    // The teacher scores one answer by hand: re-grading must not touch it.
    $manual_id = (int) $conn->query("SELECT id FROM attempt_answers WHERE attempt_id = {$a2['id']} AND question_id = $short")->fetch_assoc()['id'];
    save_teacher_grades($conn, $a2, QB_T1, [$manual_id => '0.5'], []);
    $audit = $conn->query("SELECT source, old_points, new_points FROM grade_audit WHERE attempt_answer_id = $manual_id")->fetch_assoc();
    assert_same(['source' => 'manual', 'old_points' => '0.00', 'new_points' => '0.50'], $audit);

    // Fix the keys: Paris is right, and 3.14 within 0.01.
    [$d] = question_from_form($conn, QB_T1, ['type' => 'short_answer', 'question_text' => 'Capital?', 'points' => '2', 'accepted' => "Lyon\nParis"]);
    save_question($conn, QB_T1, $d, $short);
    [$d] = question_from_form($conn, QB_T1, ['type' => 'numeric', 'question_text' => 'Pi?', 'points' => '2', 'numeric_value' => '3.14', 'tolerance' => '0.01']);
    save_question($conn, QB_T1, $d, $num);

    $preview = regrade_quiz($conn, $qid, QB_T1, false);
    assert_same(3, $preview['changed'], 'a1 both answers + a2 numeric (a2 short is manual)');
    assert_same('0.00', $conn->query("SELECT total_score FROM quiz_attempts WHERE id = {$a1['id']}")->fetch_assoc()['total_score'], 'preview writes nothing');

    regrade_quiz($conn, $qid, QB_T1, true);
    $t1 = $conn->query("SELECT total_score FROM quiz_attempts WHERE id = {$a1['id']}")->fetch_assoc()['total_score'];
    $t2 = $conn->query("SELECT total_score FROM quiz_attempts WHERE id = {$a2['id']}")->fetch_assoc()['total_score'];
    assert_same('4.00', $t1);
    assert_same('2.50', $t2, 'manual 0.5 kept + numeric 2');
    assert_same(3, (int) $conn->query("SELECT COUNT(*) c FROM grade_audit WHERE source = 'regrade'")->fetch_assoc()['c']);
    $snap_key = $conn->query("SELECT answer_config FROM attempt_questions WHERE attempt_id = {$a1['id']} AND question_id = $short")->fetch_assoc()['answer_config'];
    assert_contains('Paris', $snap_key, 'the snapshot now shows the key it was graded with');
    assert_same(0, regrade_quiz($conn, $qid, QB_T1, false)['changed'], 'a second re-grade changes nothing');
});
