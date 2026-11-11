<?php
// Question types and automatic grading (includes/grading.php) and the
// importer's extra question types. Pure functions, no database.
require_once APP_ROOT . '/includes/grading.php';
require_once APP_ROOT . '/includes/question_import.php';

function q_opts(array $correct_flags): array
{
    $out = [];
    foreach ($correct_flags as $i => $c) {
        $out[] = ['id' => 100 + $i, 'text' => "Option $i", 'correct' => $c];
    }
    return $out;
}

test('normalize_answer_text() handles case, spaces and punctuation', function () {
    assert_same('hello world', normalize_answer_text("  Hello \t World "));
    assert_same('Hello World', normalize_answer_text(' Hello  World', true));
    assert_same('its a dogs life', normalize_answer_text("It's a dog's life!", false, true));
    assert_same('ÉCOLE', normalize_answer_text('ÉCOLE', true));
    assert_same('école', normalize_answer_text('ÉCOLE'));
});

test('parse_number() accepts plain, signed, decimal-comma and scientific numbers', function () {
    assert_same(3.5, parse_number('3.5'));
    assert_same(-2.0, parse_number(' -2 '));
    assert_same(3.5, parse_number('3,5'));
    assert_same(0.001, parse_number('1e-3'));
    assert_same(null, parse_number('abc'));
    assert_same(null, parse_number('1,000.5,3'));
    assert_same(null, parse_number(''));
});

test('single choice and true/false grade against the one correct option', function () {
    $q = ['type' => 'mcq', 'points' => 2, 'options' => q_opts([false, true, false])];
    assert_same(2.0, grade_question($q, '101')['points']);
    assert_same(0.0, grade_question($q, '100')['points']);
    assert_same(0.0, grade_question($q, null)['points'], 'unanswered is wrong, not pending');
    $tf = ['type' => 'true_false', 'points' => 1, 'options' => q_opts([true, false])];
    assert_same(1, grade_question($tf, '100')['is_correct']);
    assert_same(100, grade_question($tf, '100')['selected_option_id']);
});

test('multiple select: all-or-nothing and partial credit', function () {
    $q = ['type' => 'mcq_multi', 'points' => 4, 'options' => q_opts([true, true, false, false]), 'config' => ['scoring' => 'all_or_nothing']];
    assert_same(4.0, grade_question($q, ['101', '100'])['points']);
    assert_same(0.0, grade_question($q, ['100'])['points']);
    assert_same(0.0, grade_question($q, ['100', '101', '102'])['points']);

    $q['config'] = ['scoring' => 'partial'];
    assert_same(2.0, grade_question($q, ['100'])['points'], 'one of two right');
    assert_same(0.0, grade_question($q, ['100', '102'])['points'], 'a wrong choice cancels a right one');
    assert_same(2.0, grade_question($q, ['100', '101', '102'])['points']);
    $r = grade_question($q, ['100', '101']);
    assert_same([4.0, 1], [$r['points'], $r['is_correct']]);
    assert_same('[100,101]', $r['answer_json']);
});

test('short answer: several accepted answers, case and punctuation rules', function () {
    $q = ['type' => 'short_answer', 'points' => 2, 'config' => ['accepted' => ['Oxygen', 'O2'], 'case_sensitive' => false, 'ignore_punctuation' => true]];
    assert_same(2.0, grade_question($q, ' oxygen. ')['points']);
    assert_same(2.0, grade_question($q, 'o2')['points']);
    assert_same(0.0, grade_question($q, 'carbon')['points']);
    $q['config']['case_sensitive'] = true;
    assert_same(0.0, grade_question($q, 'oxygen')['points']);
    $q['config']['accepted'] = [];
    assert_same(null, grade_question($q, 'anything')['points'], 'no key: pending for the teacher');
});

test('legacy short answers keep their original rule', function () {
    $q = ['type' => 'short_answer', 'points' => 1, 'model_answer' => 'Paris', 'answer_config' => null];
    assert_same(1.0, grade_question($q, ' PARIS ')['points']);
    assert_same(0.0, grade_question($q, 'Paris.')['points'], 'punctuation still counts for old questions');
    assert_same(null, grade_question(['type' => 'short_answer', 'points' => 1, 'model_answer' => null], 'x')['points']);
});

test('numerical answers within an absolute or percentage tolerance', function () {
    $q = ['type' => 'numeric', 'points' => 3, 'config' => ['value' => 9.81, 'tolerance' => 0.05, 'tolerance_type' => 'absolute']];
    assert_same(3.0, grade_question($q, '9.8')['points']);
    assert_same(3.0, grade_question($q, '9,86')['points']);
    assert_same(0.0, grade_question($q, '9.7')['points']);
    assert_same(0.0, grade_question($q, 'nine')['points']);
    $q['config'] = ['value' => 200, 'tolerance' => 5, 'tolerance_type' => 'percent'];
    assert_same(3.0, grade_question($q, '210')['points']);
    assert_same(0.0, grade_question($q, '211')['points']);
    $q['config'] = ['value' => 1, 'tolerance' => 0];
    assert_same(3.0, grade_question($q, '1.0')['points'], 'exact match with no tolerance');
});

test('fill in the blanks: partial credit per blank or all-or-nothing', function () {
    $q = ['type' => 'fill_blank', 'points' => 4, 'question_text' => '___ is in ___.',
        'config' => ['blanks' => [['Paris'], ['France', 'French Republic']], 'partial' => true]];
    assert_same(4.0, grade_question($q, ['paris', 'french republic'])['points']);
    assert_same(2.0, grade_question($q, ['Paris', 'Spain'])['points']);
    $q['config']['partial'] = false;
    assert_same(0.0, grade_question($q, ['Paris', 'Spain'])['points']);
    $r = grade_question($q, ['Paris', 'France']);
    assert_same('Paris | France', $r['answer_text']);
});

test('essays are always left for the teacher', function () {
    $r = grade_question(['type' => 'essay', 'points' => 10], 'A long answer');
    assert_same(null, $r['points']);
    assert_same('A long answer', $r['answer_text']);
});

test('sanitize_answer_value() keeps only well-formed answers per type', function () {
    $mcq = ['type' => 'mcq', 'options' => q_opts([true, false])];
    assert_same('100', sanitize_answer_value($mcq, '100'));
    assert_same(null, sanitize_answer_value($mcq, '999'), 'not an option of this question');
    assert_same(null, sanitize_answer_value($mcq, ['100']));

    $multi = ['type' => 'mcq_multi', 'options' => q_opts([true, false, true])];
    assert_same(['100', '102'], sanitize_answer_value($multi, ['100', '102', '100', '555', 'x']));
    assert_same(null, sanitize_answer_value($multi, []));

    $blank = ['type' => 'fill_blank', 'question_text' => 'A ___ and ___ and ___', 'options' => []];
    assert_same(['x', '', 'z'], sanitize_answer_value($blank, ['x', '', 'z', 'extra']));
    assert_same(null, sanitize_answer_value($blank, ['', ' ']));

    assert_same('3.5', sanitize_answer_value(['type' => 'numeric'], ' 3.5 '));
    assert_same(ESSAY_ANSWER_MAX, mb_strlen(sanitize_answer_value(['type' => 'essay'], str_repeat('a', ESSAY_ANSWER_MAX + 50))));
});

test('correct_answer_summary() describes each type', function () {
    assert_same('Oxygen / O2', correct_answer_summary(['type' => 'short_answer', 'config' => ['accepted' => ['Oxygen', 'O2']]]));
    assert_same('9.81 (± 0.05)', correct_answer_summary(['type' => 'numeric', 'config' => ['value' => 9.81, 'tolerance' => 0.05]]));
    assert_same('1: Paris · 2: France / FR', correct_answer_summary(['type' => 'fill_blank', 'config' => ['blanks' => [['Paris'], ['France', 'FR']]]]));
    assert_same('Option 0; Option 2', correct_answer_summary(['type' => 'mcq_multi', 'options' => q_opts([true, false, true])]));
});

test('the importer reads every question type', function () {
    $r = parse_question_markdown(sample_question_markdown_all_types());
    assert_same([], $r['errors']);
    assert_same(['mcq', 'true_false', 'mcq_multi', 'short_answer', 'numeric', 'fill_blank', 'essay'], array_column($r['questions'], 'type'));
    [$mcq, $tf, $multi, $short, $num, $blank] = $r['questions'];
    assert_same('Mercury orbits closest to the Sun.', $mcq['explanation']);
    assert_same(0, $tf['correct'], 'Answer: True -> option 0');
    assert_same([0, 1, 3], $multi['correct_list']);
    assert_same(['Oxygen', 'O2'], $short['config']['accepted']);
    assert_same(['value' => 9.81, 'tolerance' => 0.05, 'tolerance_type' => 'absolute'], $num['config']);
    assert_same([['Paris'], ['Seine']], $blank['config']['blanks']);
});

test('the importer reports problems with the new types', function () {
    $r = parse_question_markdown("## Q1 [blank]\nThe ___ and the ___.\nAnswer: one\n");
    assert_contains('2 blank(s)', $r['errors'][0]);
    $r = parse_question_markdown("## Q1 [tf]\nSky is blue\nAnswer: maybe\n");
    assert_contains('True', $r['errors'][0]);
    $r = parse_question_markdown("## Q1 [numeric]\nPi?\nAnswer: about three\n");
    assert_contains('numerical', $r['errors'][0]);
    $r = parse_question_markdown("## Q1 [multi]\nPick\n- [ ] a\n- [ ] b\nAnswer: A, B\n");
    assert_same([0, 1], $r['questions'][0]['correct_list'], 'Answer: A, B marks two options');
    $r = parse_question_markdown("## Q1 [numeric]\nGrowth?\nAnswer: 200 +/- 5%\n");
    assert_same('percent', $r['questions'][0]['config']['tolerance_type']);
});
