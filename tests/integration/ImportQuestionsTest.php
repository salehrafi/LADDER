<?php
require_once APP_ROOT . '/includes/question_import.php';

test('import_questions() appends after existing questions with options and answers', function () {
    reset_test_db();
    $conn = test_db();
    $quiz = make_quiz($conn, [['short_answer', 'Existing', 1, null, []]]);
    $quiz_id = $quiz['quiz_id'];

    $parsed = parse_question_markdown(sample_question_markdown());
    assert_same(4, import_questions($conn, $quiz_id, $parsed['questions']));

    $rows = $conn->query("SELECT id, type, question_text, points, order_index, model_answer FROM questions WHERE quiz_id = $quiz_id ORDER BY order_index")->fetch_all(MYSQLI_ASSOC);
    assert_same(['Existing', 'Which pigment gives leaves their green colour?'], [$rows[0]['question_text'], $rows[1]['question_text']]);
    assert_same(['0', '1', '2', '3', '4'], array_column($rows, 'order_index'));
    assert_same('Oxygen', $rows[3]['model_answer']);
    assert_same(null, $rows[4]['model_answer']);
    assert_same('2.00', $rows[3]['points']);

    $mcq_id = (int) $rows[1]['id'];
    $opts = $conn->query("SELECT option_text, is_correct FROM question_options WHERE question_id = $mcq_id ORDER BY order_index")->fetch_all(MYSQLI_ASSOC);
    assert_same(['Carotene', 'Chlorophyll', 'Xanthophyll', 'Anthocyanin'], array_column($opts, 'option_text'));
    assert_same(['0', '1', '0', '0'], array_column($opts, 'is_correct'));
});

test('imported questions grade like hand-written ones', function () {
    reset_test_db();
    $conn = test_db();
    $quiz = make_quiz($conn, []);
    $parsed = parse_question_markdown("## Q1 [mcq] (2 points)\nPick\n- [ ] no\n- [x] yes\n\n## Q2 [short]\nSay hi\nAnswer: Hi\n");
    import_questions($conn, $quiz['quiz_id'], $parsed['questions']);

    $qs = $conn->query("SELECT id FROM questions WHERE quiz_id = {$quiz['quiz_id']} ORDER BY order_index")->fetch_all(MYSQLI_ASSOC);
    $yes = (int) $conn->query("SELECT id FROM question_options WHERE question_id = {$qs[0]['id']} AND is_correct = 1")->fetch_assoc()['id'];
    $quiz['questions'] = [['id' => (int) $qs[0]['id']], ['id' => (int) $qs[1]['id']]];
    $attempt = make_attempt($conn, $quiz);

    require_once APP_ROOT . '/includes/quiz_helpers.php';
    assert_true(finalize_attempt($conn, $attempt, ['q_' . $qs[0]['id'] => (string) $yes, 'q_' . $qs[1]['id'] => ' hi ']));
    $row = $conn->query("SELECT total_score, max_score FROM quiz_attempts WHERE id = {$attempt['id']}")->fetch_assoc();
    assert_same(['total_score' => '3.00', 'max_score' => '3.00'], $row);
});
