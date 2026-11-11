<?php
require_once APP_ROOT . '/includes/question_import.php';

test('parse_question_markdown() reads MCQ and short-answer questions', function () {
    $md = "# Quiz: Plants\n\nIntro text is ignored.\n\n## Q1 [mcq] (1 point)\nWhich pigment is green?\n- [ ] Carotene\n- [x] Chlorophyll\n- [ ] Xanthophyll\n\n## Q2 [short] (2 points)\nWhich gas is released?\nAnswer: Oxygen\n";
    $r = parse_question_markdown($md);
    assert_same([], $r['errors']);
    assert_same(2, count($r['questions']));

    [$q1, $q2] = $r['questions'];
    assert_same('mcq', $q1['type']);
    assert_same('Which pigment is green?', $q1['text']);
    assert_same(['Carotene', 'Chlorophyll', 'Xanthophyll'], $q1['options']);
    assert_same(1, $q1['correct']);
    assert_same(1.0, $q1['points']);

    assert_same('short_answer', $q2['type']);
    assert_same('Oxygen', $q2['answer']);
    assert_same(2.0, $q2['points']);
});

test('parse_question_markdown() handles the sample file', function () {
    $r = parse_question_markdown(sample_question_markdown());
    assert_same([], $r['errors']);
    assert_same(4, count($r['questions']));
    assert_same(null, $r['questions'][3]['answer'], '"Answer: none" means grade by hand');
});

test('parse_question_markdown() tolerates common AI variations', function () {
    $md = "```markdown\r\n### Question 1: (MCQ) [2 marks]\r\n**What is 2 + 2?**\r\n* [X] A) 4\r\n* [ ] B) 5\r\n\r\n---\r\n\r\n## 2. What is the capital of France?\r\n**Answer:** Paris\r\nExplanation: it is.\r\n```\r\n";
    $r = parse_question_markdown("\xEF\xBB\xBF" . $md);
    assert_same([], $r['errors']);
    assert_same(['4', '5'], $r['questions'][0]['options'], 'letters stripped');
    assert_same(0, $r['questions'][0]['correct']);
    assert_same(2.0, $r['questions'][0]['points']);
    assert_same('short_answer', $r['questions'][1]['type'], 'type inferred: no options');
    assert_same('What is the capital of France?', $r['questions'][1]['text'], 'question text in the heading');
    assert_same('Paris', $r['questions'][1]['answer']);
});

test('parse_question_markdown() keeps a leading number that is part of the question', function () {
    $r = parse_question_markdown("## 5 + 3 = ?\n- [ ] 7\n- [x] 8\n\n## 2. What is 2 + 2?\nAnswer: 4\n\n## Q3: Name a prime.\nAnswer: 7\n");
    assert_same([], $r['errors']);
    assert_same('5 + 3 = ?', $r['questions'][0]['text'], 'number kept');
    assert_same('What is 2 + 2?', $r['questions'][1]['text'], '"2." is a label');
    assert_same('Name a prime.', $r['questions'][2]['text'], '"Q3:" is a label');
});

test('parse_question_markdown() accepts "Answer: B" for an MCQ with no [x]', function () {
    $r = parse_question_markdown("## Q1 [mcq]\nPick one\n- [ ] Red\n- [ ] Blue\nAnswer: B\n");
    assert_same([], $r['errors']);
    assert_same(1, $r['questions'][0]['correct']);
});

test('parse_question_markdown() keeps multi-line question text', function () {
    $r = parse_question_markdown("## Q1 [short]\nRead the code:\n\n    x = 2 * 3\n\nWhat is x?\nAnswer: 6\n");
    assert_same("Read the code:\n\n    x = 2 * 3\n\nWhat is x?", $r['questions'][0]['text']);
});

test('parse_question_markdown() rejects what would grade wrongly', function () {
    $cases = [
        "## Q1 [mcq]\nPick\n- [x] A\n- [x] B\n" => 'more than one option',
        "## Q1 [mcq]\nPick\n- [ ] A\n- [ ] B\n" => 'no option is marked correct',
        "## Q1 [mcq]\nPick\n- [x] A\n" => 'at least 2 options',
        "## Q1 [short]\nPick\n- [x] A\n- [ ] B\n" => 'marked [short] but has options',
        "## Q1 [mcq]\n- [x] A\n- [ ] B\n" => 'question text is missing',
        "## Q1 [short] (0 points)\nWhy?\n" => 'points must be',
        "## Q1 [mcq]\nPick\n- [x] A\n- [ ] B\nstray words\n" => 'unexpected text after the options',
        "Just some text without headings" => 'No questions found',
    ];
    foreach ($cases as $md => $expected) {
        $r = parse_question_markdown($md);
        assert_same([], $r['questions'], "nothing parsed for: $expected");
        assert_contains($expected, $r['errors'][0] ?? '', $md);
    }
});

test('parse_question_markdown() reports every bad question with its line', function () {
    $r = parse_question_markdown("## Q1 [short]\nOk?\nAnswer: yes\n\n## Q2 [mcq]\nBad\n- [ ] A\n- [ ] B\n\n## Q3 [mcq]\n- [x] A\n- [ ] B\n");
    assert_same(1, count($r['questions']));
    assert_same(2, count($r['errors']));
    assert_contains('Question 2 (line 5)', $r['errors'][0]);
    assert_contains('Question 3 (line 10)', $r['errors'][1]);
});

test('parse_question_markdown() rejects invalid UTF-8 and too many questions', function () {
    assert_contains('UTF-8', parse_question_markdown("## Q1\n\xFF\xFE bad")['errors'][0]);
    $many = str_repeat("## Q [short]\nWhy?\n", MD_IMPORT_MAX_QUESTIONS + 1);
    assert_contains('Too many questions', parse_question_markdown($many)['errors'][0]);
});

test('build_question_prompt() fills the template and drops an empty extra line', function () {
    $p = build_question_prompt(['topic' => 'Fractions', 'mcq' => 7, 'short' => 0, 'extra' => '']);
    assert_contains('Topic: Fractions', $p);
    assert_contains('exactly 7 multiple-choice questions and 0 short-answer', $p);
    assert_not_contains('{{', $p);
    assert_not_contains('Extra instructions', $p);
    assert_contains('Extra instructions: use taka', build_question_prompt(['extra' => 'use taka']));

    // The example in the prompt must itself be importable once filled in.
    preg_match('/(## Q1.*?Answer: <the short answer>)/s', $p, $m);
    $example = str_replace(['<question text>', '<wrong option>', '<correct option>', '<the short answer>'], ['Q?', 'no', 'yes', 'ok'], $m[1]);
    $r = parse_question_markdown($example);
    assert_same([], $r['errors']);
    assert_same(2, count($r['questions']));
});
