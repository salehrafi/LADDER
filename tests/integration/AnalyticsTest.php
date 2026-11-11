<?php
// Learning analytics and item analysis. Runs on the isolated test database.
require_once APP_ROOT . '/includes/class_helpers.php';
require_once APP_ROOT . '/includes/assignments.php';
require_once APP_ROOT . '/includes/question_bank.php';
require_once APP_ROOT . '/includes/practice.php';
require_once APP_ROOT . '/includes/analytics.php';

const AN_T = 'an-t@test.local';
const AN_T2 = 'an-t2@test.local';

/** A teacher, students s1..s$n in one section, and a two-question MCQ quiz with topics. */
function an_fixture(int $students = 4): array
{
    reset_test_db();
    $conn = test_db();
    foreach (['section_students', 'sections', 'topics', 'subjects'] as $t) {
        $conn->query("SET FOREIGN_KEY_CHECKS = 0");
        $conn->query("DELETE FROM `$t`");
        $conn->query("SET FOREIGN_KEY_CHECKS = 1");
    }
    ensure_test_account($conn, AN_T, 'teacher');
    ensure_test_account($conn, AN_T2, 'teacher');
    $sid = ensure_section($conn, AN_T, 'An Sec');
    for ($i = 1; $i <= $students; $i++) {
        ensure_test_account($conn, "an-s$i@test.local", 'student');
        $conn->query("INSERT INTO section_students (section_id, student_email) VALUES ($sid, 'an-s$i@test.local')");
    }
    $subject = create_subject($conn, AN_T, 'Science');
    $t_cells = create_topic($conn, AN_T, $subject, 'Cells');
    $t_atoms = create_topic($conn, AN_T, $subject, 'Atoms');
    $quiz = make_quiz($conn, [
        ['mcq', 'Cell question', 1, null, ['right' => true, 'wrong' => false, 'other' => false]],
        ['mcq', 'Atom question', 1, null, ['right' => true, 'wrong' => false]],
    ], ['teacher' => AN_T, 'max_attempts' => 0]);
    [$q1, $q2] = $quiz['questions'];
    $conn->query("UPDATE questions SET topic_id = $t_cells WHERE id = {$q1['id']}");
    $conn->query("UPDATE questions SET topic_id = $t_atoms WHERE id = {$q2['id']}");
    $conn->query("UPDATE quizzes SET subject_id = $subject WHERE id = {$quiz['quiz_id']}");
    return [$conn, $quiz, $sid];
}

/** Submit an attempt choosing option labels per question (null = no answer). */
function an_take(mysqli $conn, array $quiz, string $student, array $choices, bool $practice = false): array
{
    $q = $conn->query("SELECT * FROM quizzes WHERE id = {$quiz['quiz_id']}")->fetch_assoc();
    $attempt = start_or_resume_attempt($conn, $q, $student, null, $practice)['attempt'];
    $answers = [];
    foreach ($quiz['questions'] as $i => $question) {
        if (($choices[$i] ?? null) !== null) {
            $answers['q_' . $question['id']] = (string) $question['options'][$choices[$i]];
        }
    }
    finalize_attempt($conn, $attempt, $answers);
    return $attempt;
}

test('median() of odd and even lists', function () {
    assert_same(2.0, median([3, 1, 2]));
    assert_same(2.5, median([4, 1, 2, 3]));
    assert_same(null, median([]));
});

test('student trend and topics use released, graded, official attempts only', function () {
    [$conn, $quiz] = an_fixture(1);
    an_take($conn, $quiz, 'an-s1@test.local', ['right', 'wrong']);   // 50%
    an_take($conn, $quiz, 'an-s1@test.local', ['right', 'right']);   // 100%
    an_take($conn, $quiz, 'an-s1@test.local', ['wrong', 'wrong'], true); // practice: ignored

    $trend = student_score_trend($conn, 'an-s1@test.local');
    assert_same([50.0, 100.0], array_column($trend, 'pct'));

    $topics = [];
    foreach (student_group_performance($conn, 'an-s1@test.local', 'topic') as $t) {
        $topics[$t['name']] = [$t['pct'], $t['answers']];
    }
    assert_same(['Atoms' => [50.0, 2], 'Cells' => [100.0, 2]], $topics);
    assert_same([['name' => 'Science', 'pct' => 75.0, 'answers' => 4]], student_group_performance($conn, 'an-s1@test.local', 'subject'));

    $s = student_strengths($conn, 'an-s1@test.local');
    assert_same(['Cells'], array_column($s['strong'], 'name'));
    assert_same(['Atoms'], array_column($s['weak'], 'name'), '50% is below the 60% line');
});

test('results the teacher has not released stay out of student analytics', function () {
    [$conn, $quiz] = an_fixture(1);
    $conn->query("UPDATE quizzes SET results_release = 'manual' WHERE id = {$quiz['quiz_id']}");
    an_take($conn, $quiz, 'an-s1@test.local', ['right', 'right']);
    assert_same([], student_score_trend($conn, 'an-s1@test.local'));
    assert_same([], student_group_performance($conn, 'an-s1@test.local', 'topic'));
    $conn->query("UPDATE quizzes SET results_released_at = NOW() WHERE id = {$quiz['quiz_id']}");
    assert_same(1, count(student_score_trend($conn, 'an-s1@test.local')));
});

test('student_completion() counts statuses of assigned official quizzes', function () {
    [$conn, $quiz] = an_fixture(1);
    $late = make_quiz($conn, [['mcq', 'Q', 1, null, ['a' => true]]], ['teacher' => AN_T, 'title' => 'Overdue one']);
    assign_quiz($conn, AN_T, $late['quiz_id'], 'all', [], null, date('Y-m-d H:i:s', time() - 3600), null);
    an_take($conn, $quiz, 'an-s1@test.local', ['right', 'right']);
    $c = student_completion($conn, 'an-s1@test.local');
    assert_same([2, 1, 1, 50.0], [$c['total'], $c['completed'], $c['overdue'], $c['rate']]);
});

test('class_analytics() summarises, filters and keeps teachers apart', function () {
    [$conn, $quiz, $sid] = an_fixture(4);
    an_take($conn, $quiz, 'an-s1@test.local', ['right', 'right']);  // 100
    an_take($conn, $quiz, 'an-s2@test.local', ['right', 'wrong']);  // 50
    an_take($conn, $quiz, 'an-s3@test.local', ['wrong', 'wrong']);  // 0
    an_take($conn, $quiz, 'an-s4@test.local', ['right', 'right'], true); // practice: ignored
    $other = make_quiz($conn, [['mcq', 'Theirs', 1, null, ['a' => true]]], ['teacher' => AN_T2]);
    an_take($conn, $other, 'an-s4@test.local', ['a']);

    $d = class_analytics($conn, AN_T);
    assert_same(3, $d['summary']['students']);
    assert_same(50.0, $d['summary']['average']);
    assert_same(50.0, $d['summary']['median']);
    assert_same(66.7, $d['summary']['pass_rate']);
    assert_same(1, $d['distribution']['0–10']);
    assert_same(1, $d['distribution']['50–60']);
    assert_same(1, $d['distribution']['90–100'], '100% falls in the top band');
    assert_same(['Atoms', 'Cells'], array_column($d['topics'], 'name'), 'weakest topic first');
    assert_same('An Sec', $d['sections'][0]['name']);
    assert_same(0, class_analytics($conn, AN_T, 0, $other['quiz_id'])['summary']['attempts'], "another teacher's quiz cannot be filtered in");

    $sid2 = ensure_section($conn, AN_T, 'Small');
    $conn->query("INSERT INTO section_students (section_id, student_email) VALUES ($sid2, 'an-s1@test.local')");
    assert_same(['an-s1@test.local'], array_column(class_analytics($conn, AN_T, $sid2)['students'], 'email'));
});

test('question_analysis() measures difficulty, discrimination and option choices', function () {
    [$conn, $quiz] = an_fixture(8);
    // Strong students get both right; weak ones miss Q1 (a discriminating
    // question) but everyone gets Q2 right (no discrimination).
    $plan = [
        1 => ['right', 'right'], 2 => ['right', 'right'], 3 => ['right', 'right'], 4 => ['right', 'right'],
        5 => ['wrong', 'right'], 6 => ['other', 'right'], 7 => ['wrong', 'right'], 8 => [null, 'right'],
    ];
    foreach ($plan as $i => $choices) {
        an_take($conn, $quiz, "an-s$i@test.local", $choices);
    }
    $items = question_analysis($conn, $quiz['quiz_id']);
    [$q1, $q2] = $items;
    assert_same(0.5, $q1['difficulty']);
    assert_same(1.0, $q1['discrimination'], 'top group all right, bottom group all wrong');
    assert_same(1.0, $q2['difficulty']);
    assert_same(0.0, $q2['discrimination']);
    assert_contains('Very easy', $q2['flag']);
    $counts = array_column($q1['options'], 'count', 'text');
    assert_same(['right' => 4, 'wrong' => 2, 'other' => 1], $counts);
    assert_same(1, $q1['blank']);
    assert_true($q1['options'][0]['correct']);
});
