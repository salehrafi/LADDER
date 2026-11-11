<?php
// Question bank and new question types, end to end through the web server.
// Uses @http-test.local accounts only and removes them afterwards.

require_once __DIR__ . '/support.php';

function qbf_db_one(string $sql): ?array
{
    return http_app_db()->query($sql)->fetch_assoc() ?: null;
}

test('bank setup: two teachers, a student in a section, an empty quiz', function () {
    http_cleanup();
    $db = http_app_db();
    $db->query("DELETE FROM subjects WHERE teacher_email LIKE '%@http-test.local'");
    http_make_account('teacher', 'qbf-t1@http-test.local');
    http_make_account('teacher', 'qbf-t2@http-test.local');
    http_make_account('student', 'qbf-s@http-test.local');
    $db->query("INSERT INTO sections (teacher_email, name) VALUES ('qbf-t1@http-test.local', 'HTTP Test Bank')");
    $sid = (int) $db->insert_id;
    $db->query("INSERT INTO section_students (section_id, student_email) VALUES ($sid, 'qbf-s@http-test.local')");
    $db->query("INSERT INTO quizzes (teacher_email, title, status, lock_on_leave, results_release) VALUES ('qbf-t1@http-test.local', 'Bank Flow Quiz', 'draft', 0, 'immediate')");
    $GLOBALS['qbf'] = ['quiz_id' => (int) $db->insert_id, 't1' => http_login('qbf-t1@http-test.local')];
});

test('teacher builds a subject, a topic and a bank question', function () {
    $t = $GLOBALS['qbf']['t1'] ?? fail('setup did not run');
    $page = 'dashboards/teachersPages/question_bank.php';
    $token = $t->get($page)->csrf();
    assert_contains('Question Bank', $t->body);

    $t->post($page, ['csrf_token' => $token, 'action' => 'create_subject', 'name' => 'Physics']);
    assert_same(302, $t->status);
    $subject = qbf_db_one("SELECT id FROM subjects WHERE teacher_email = 'qbf-t1@http-test.local'");
    $t->post($page, ['csrf_token' => $token, 'action' => 'create_topic', 'subject_id' => $subject['id'], 'name' => 'Gravity']);
    $topic = qbf_db_one("SELECT id FROM topics WHERE subject_id = {$subject['id']}");
    assert_true($topic !== null, 'topic created');

    $t->post($page, [
        'csrf_token' => $token, 'action' => 'add_question', 'type' => 'numeric', 'question_text' => 'g on Earth (m/s²)?',
        'points' => '2', 'numeric_value' => '9.81', 'tolerance' => '0.05', 'tolerance_type' => 'absolute',
        'difficulty' => 'easy', 'topic_id' => $topic['id'], 'explanation' => 'Standard gravity.',
    ]);
    assert_same(302, $t->status);
    $q = qbf_db_one("SELECT id, quiz_id, topic_id, answer_config FROM questions WHERE teacher_email = 'qbf-t1@http-test.local' AND quiz_id IS NULL");
    assert_true($q !== null && $q['quiz_id'] === null, 'saved in the bank');
    assert_contains('9.81', $q['answer_config']);
    $body = $t->get($page)->body;
    assert_contains('g on Earth', $body);
    assert_contains('Physics: Gravity', $body);
    $GLOBALS['qbf']['bank_id'] = (int) $q['id'];
});

test('another teacher can neither see nor change my bank question', function () {
    $bank_id = $GLOBALS['qbf']['bank_id'] ?? fail('previous test did not run');
    $t2 = http_login('qbf-t2@http-test.local');
    $page = 'dashboards/teachersPages/question_bank.php';
    $body = $t2->get($page . '?edit=' . $bank_id)->body;
    assert_not_contains('g on Earth', $body);
    $token = $t2->csrf();
    $t2->post($page, ['csrf_token' => $token, 'action' => 'update_question', 'question_id' => $bank_id, 'type' => 'essay', 'question_text' => 'Hijacked', 'points' => '1']);
    assert_same('g on Earth (m/s²)?', qbf_db_one("SELECT question_text FROM questions WHERE id = $bank_id")['question_text']);
    $t2->post($page, ['csrf_token' => $token, 'action' => 'add_to_quiz', 'quiz_id' => $GLOBALS['qbf']['quiz_id'], 'ids' => [$bank_id]]);
    assert_same(0, (int) qbf_db_one("SELECT COUNT(*) c FROM questions WHERE quiz_id = {$GLOBALS['qbf']['quiz_id']}")['c']);
});

test('teacher adds the bank question to a quiz and writes more types there', function () {
    $t = $GLOBALS['qbf']['t1'];
    $quiz_id = $GLOBALS['qbf']['quiz_id'];
    $t->post('dashboards/teachersPages/question_bank.php', ['csrf_token' => $t->get('dashboards/teachersPages/question_bank.php')->csrf(), 'action' => 'add_to_quiz', 'quiz_id' => $quiz_id, 'ids' => [$GLOBALS['qbf']['bank_id']]]);
    $copy = qbf_db_one("SELECT source_question_id FROM questions WHERE quiz_id = $quiz_id");
    assert_same((string) $GLOBALS['qbf']['bank_id'], (string) $copy['source_question_id']);

    $page = "dashboards/teachersPages/quiz_questions.php?quiz_id=$quiz_id";
    $token = $t->get($page)->csrf();
    assert_contains('Add from your question bank', $t->body);
    $t->post($page, ['csrf_token' => $token, 'quiz_id' => $quiz_id, 'action' => 'add_question', 'type' => 'mcq_multi',
        'question_text' => 'Which are prime?', 'points' => '2', 'options' => ['2', '3', '4'], 'correct' => ['0', '1'], 'scoring' => 'all_or_nothing']);
    $t->post($page, ['csrf_token' => $token, 'quiz_id' => $quiz_id, 'action' => 'add_question', 'type' => 'fill_blank',
        'question_text' => 'The capital of France is ___.', 'points' => '1', 'blank_answers' => 'Paris', 'partial' => '1', 'ignore_punctuation' => '1']);
    assert_same(3, (int) qbf_db_one("SELECT COUNT(*) c FROM questions WHERE quiz_id = $quiz_id AND deleted_at IS NULL")['c']);
    http_app_db()->query("UPDATE quizzes SET status = 'published' WHERE id = $quiz_id");
});

test('student answers multiple select, blanks and a number through the real form', function () {
    $quiz_id = $GLOBALS['qbf']['quiz_id'] ?? fail('setup did not run');
    $s = http_login('qbf-s@http-test.local');
    $s->get("dashboards/studentPages/take_quiz.php?quiz_id=$quiz_id&start=1");
    $page = $s->get("dashboards/studentPages/take_quiz.php?quiz_id=$quiz_id")->body;
    preg_match('/name="attempt_id" value="(\d+)"/', $page, $m) || fail('no attempt');
    $attempt_id = (int) $m[1];
    assert_contains('Select all that apply', $page);
    assert_same(3, preg_match_all('/type="checkbox" name="q_\d+\[\]"/', $page), 'multiple-select options are on the page');
    assert_contains('data-blank', $page);
    assert_contains('inputmode="decimal"', $page);

    $db = http_app_db();
    $ids = [];
    foreach ($db->query("SELECT id, type FROM questions WHERE quiz_id = $quiz_id")->fetch_all(MYSQLI_ASSOC) as $r) {
        $ids[$r['type']] = (int) $r['id'];
    }
    $primes = array_column($db->query("SELECT id FROM question_options WHERE question_id = {$ids['mcq_multi']} AND is_correct = 1")->fetch_all(MYSQLI_ASSOC), 'id');

    $s->post('dashboards/studentPages/take_quiz.php', [
        'csrf_token' => $s->csrf(), 'action' => 'submit_attempt', 'quiz_id' => $quiz_id, 'attempt_id' => $attempt_id,
        'q_' . $ids['numeric'] => '9,8',
        'q_' . $ids['mcq_multi'] => $primes,
        'q_' . $ids['fill_blank'] => ['paris.'],
    ]);
    assert_same(302, $s->status);
    $row = qbf_db_one("SELECT total_score, max_score FROM quiz_attempts WHERE id = $attempt_id");
    assert_same(['total_score' => '5.00', 'max_score' => '5.00'], $row);

    $review = $s->get("dashboards/studentPages/view_attempt.php?attempt_id=$attempt_id")->body;
    assert_contains('Standard gravity.', $review, 'explanation shown with released answers');
    assert_contains('Correct value', $review);
});

test('bank teardown', function () {
    http_cleanup();
    http_app_db()->query("DELETE FROM subjects WHERE teacher_email LIKE '%@http-test.local'");
    assert_same(0, (int) qbf_db_one("SELECT COUNT(*) c FROM reg_info WHERE email LIKE '%@http-test.local'")['c']);
});
