<?php
/**
 * End-to-end tests through the real web server: registration, login, CSRF,
 * quiz authoring, taking a quiz, grading and export.
 *
 * They use the app's configured database, but only create accounts under
 * @http-test.local and remove them (and their quizzes) when done.
 */

require_once __DIR__ . "/support.php";

/** Sign up through the public form (teachers and students), verify the email, then log in. */
function http_register_and_login(string $role, string $email): Browser
{
    $b = new Browser(http_base());
    $sid = $role === 'student' ? 'S-' . substr(md5($email), 0, 10) : '';
    $b->post('auth/registration.php', ['id' => $sid, 'name' => ucfirst($role) . ' Tester', 'email' => $email, 'password' => HTTP_PASSWORD, 'user_type' => $role]);
    assert_contains('Account created', $b->body, "registering $email");

    http_verify_email($email);
    $b->post('auth/login.php', ['email' => $email, 'password' => HTTP_PASSWORD]);
    assert_same(302, $b->status, 'login redirects');
    assert_contains($role === 'teacher' ? 'teacherDashboard.php' : 'studentDashboard.php', $b->location);
    return $b;
}

// Shared state across the flow, in order.
$GLOBALS['flow'] = [];

test('setup: web server is reachable and test accounts are clean', function () {
    http_cleanup();
    $b = (new Browser(http_base()))->get('auth/login.php');
    assert_same(200, $b->status);
    assert_contains('name="email"', $b->body);
});

test('registration rejects duplicates and bad input without crashing', function () {
    $b = new Browser(http_base());
    $data = ['id' => 'HTTP-DUP-1', 'name' => 'Dup', 'email' => 'dup@http-test.local', 'password' => HTTP_PASSWORD, 'user_type' => 'student'];
    assert_contains('Account created', $b->post('auth/registration.php', $data)->body);
    // Signing up again before verifying sends a new link instead of failing.
    assert_contains('already waiting for verification', $b->post('auth/registration.php', $data)->body);
    http_verify_email('dup@http-test.local');
    assert_contains('already exists', $b->post('auth/registration.php', $data)->body);

    // Students must give a Student ID, and it must be unique.
    assert_contains('Student ID is required', $b->post('auth/registration.php', ['email' => 'dup2@http-test.local', 'id' => ''] + $data)->body);
    assert_contains('Student ID is already registered', $b->post('auth/registration.php', ['email' => 'dup2@http-test.local'] + $data)->body);
    assert_contains('Student ID must be', $b->post('auth/registration.php', ['email' => 'dup2@http-test.local', 'id' => 'has space'] + $data)->body);

    assert_contains('valid email', $b->post('auth/registration.php', ['email' => 'not-an-email'] + $data)->body);
    assert_contains('Password must be 6-64', $b->post('auth/registration.php', ['email' => 'x@http-test.local', 'password' => 'short'] + $data)->body);
    assert_contains('up to 50 characters', $b->post('auth/registration.php', ['email' => 'y@http-test.local', 'name' => str_repeat('n', 51)] + $data)->body);
});

test('teachers get a unique generated Teacher ID and need no ID of their own', function () {
    $b = new Browser(http_base());
    foreach (['t-id1@http-test.local', 't-id2@http-test.local'] as $email) {
        $res = $b->post('auth/registration.php', ['id' => '', 'name' => 'Teacher', 'email' => $email, 'password' => 'abc123', 'user_type' => 'teacher']);
        assert_contains('Account created', $res->body, 'a 6-character password is accepted');
    }
    $ids = array_column(http_app_db()->query("SELECT id FROM reg_info WHERE email LIKE 't-id%@http-test.local'")->fetch_all(MYSQLI_ASSOC), 'id');
    assert_same(2, count(array_unique($ids)));
    foreach ($ids as $id) {
        assert_true((bool) preg_match('/^TCH-[0-9A-F]{8}$/', (string) $id), "teacher id $id");
    }
});

test('an unverified account cannot sign in until the emailed link is opened', function () {
    $b = new Browser(http_base());
    $b->post('auth/registration.php', ['id' => 'HTTP-VER-1', 'name' => 'Ver', 'email' => 'ver@http-test.local', 'password' => HTTP_PASSWORD, 'user_type' => 'student']);
    $b->post('auth/login.php', ['email' => 'ver@http-test.local', 'password' => HTTP_PASSWORD]);
    assert_same(200, $b->status);
    assert_contains('verify your email', $b->body);

    // Use a known token (the real one is only ever emailed; just its hash is stored).
    $token = str_repeat('ab', 32);
    $hash = hash('sha256', $token);
    http_app_db()->query("UPDATE reg_info SET verify_token_hash = '$hash', verify_expires = NOW() + INTERVAL 1 HOUR WHERE email = 'ver@http-test.local'");
    $b->get('auth/verify_email.php?token=' . str_repeat('cd', 32));
    $b->get('auth/login.php');
    assert_contains('invalid or has expired', $b->body, 'a wrong token is refused');

    $b->get("auth/verify_email.php?token=$token");
    assert_contains('login.php', $b->location);
    assert_contains('Your email is verified', $b->get('auth/login.php')->body);
    $b->get("auth/verify_email.php?token=$token");
    assert_contains('invalid or has expired', $b->get('auth/login.php')->body, 'a token works once');

    $b->post('auth/login.php', ['email' => 'ver@http-test.local', 'password' => HTTP_PASSWORD]);
    assert_same(302, $b->status, 'verified account signs in');
});

test('login rejects a wrong password', function () {
    $b = (new Browser(http_base()))->post('auth/login.php', ['email' => 'dup@http-test.local', 'password' => 'Wrong@1234']);
    assert_same(200, $b->status);
    assert_contains('Invalid email or password', $b->body);
});

/** Pass the exam-mode start gate; returns the question HTML the server sends back. */
function http_begin_exam(Browser $s, int $attempt_id, string $token): string
{
    $s->post('dashboards/studentPages/begin_quiz.php', json_encode(['attempt_id' => $attempt_id, 'csrf_token' => $token]), 'application/json');
    assert_same(200, $s->status, 'begin_quiz');
    $data = json_decode($s->body, true);
    assert_true(is_array($data) && !empty($data['ok']), 'begin_quiz ok');
    return (string) ($data['questions_html'] ?? '');
}

test('teacher creates a quiz with MCQ and short-answer questions and publishes it', function () {
    $t = http_register_and_login('teacher', 'teacher@http-test.local');

    $token = $t->get('dashboards/teachersPages/setQuiz.php')->csrf();
    $t->post('dashboards/teachersPages/setQuiz.php', ['csrf_token' => $token, 'action' => 'create_quiz', 'title' => 'HTTP Flow Quiz']);
    assert_same(302, $t->status);
    assert_true((bool) preg_match('/quiz_id=(\d+)/', $t->location, $m), 'redirects to the question editor');
    $quiz_id = (int) $m[1];

    $page = 'dashboards/teachersPages/quiz_questions.php?quiz_id=' . $quiz_id;
    $token = $t->get($page)->csrf();
    $t->post($page, [
        'csrf_token' => $token, 'quiz_id' => $quiz_id, 'action' => 'add_question', 'type' => 'mcq',
        'question_text' => '2 + 2 = ?', 'points' => '2', 'options' => ['3', '4', '5'], 'correct_option' => '1',
    ]);
    // Saved, then redirected back to the editor (a refresh cannot add it twice).
    assert_same(302, $t->status);
    assert_contains('Question added.', $t->get($page)->body);
    $t->post($page, [
        'csrf_token' => $token, 'quiz_id' => $quiz_id, 'action' => 'add_question', 'type' => 'short_answer',
        'question_text' => 'Explain gravity.', 'points' => '3', 'model_answer' => '',
    ]);
    assert_same(302, $t->status);
    $n = (int) http_app_db()->query("SELECT COUNT(*) c FROM questions WHERE quiz_id = $quiz_id")->fetch_assoc()['c'];
    assert_same(2, $n, 'both questions saved');

    $t->post('dashboards/teachersPages/setQuiz.php', ['csrf_token' => $token, 'action' => 'set_status', 'quiz_id' => $quiz_id, 'status' => 'published']);
    assert_contains('Quiz status updated.', $t->body);

    $GLOBALS['flow'] += ['teacher' => $t, 'quiz_id' => $quiz_id];
});

test('POST without a valid CSRF token is rejected (403) and changes nothing', function () {
    $t = $GLOBALS['flow']['teacher'] ?? fail('previous test did not run');
    $quiz_id = $GLOBALS['flow']['quiz_id'];

    $t->post('dashboards/teachersPages/setQuiz.php', ['action' => 'delete_quiz', 'quiz_id' => $quiz_id]);
    assert_same(403, $t->status);
    $t->post('dashboards/teachersPages/setQuiz.php', ['csrf_token' => str_repeat('0', 64), 'action' => 'delete_quiz', 'quiz_id' => $quiz_id]);
    assert_same(403, $t->status);

    $row = http_app_db()->query("SELECT id FROM quizzes WHERE id = $quiz_id")->fetch_assoc();
    assert_true($row !== null, 'quiz must still exist');
});

test('a student cannot open teacher pages and vice versa', function () {
    $s = http_register_and_login('student', 'student@http-test.local');
    $s->get('dashboards/teachersPages/setQuiz.php');
    assert_same(302, $s->status);
    assert_contains('login.php', $s->location);

    $t = $GLOBALS['flow']['teacher'];
    $t->get('dashboards/studentPages/quizzes.php');
    assert_same(302, $t->status);

    $GLOBALS['flow']['student'] = $s;
});

test("a student outside the teacher's sections can neither see nor start the quiz", function () {
    $s = $GLOBALS['flow']['student'] ?? fail('previous test did not run');
    $quiz_id = $GLOBALS['flow']['quiz_id'];

    assert_not_contains('HTTP Flow Quiz', $s->get('dashboards/studentPages/quizzes.php')->body);
    $s->get("dashboards/studentPages/take_quiz.php?quiz_id=$quiz_id&start=1");
    assert_contains('quizzes.php', $s->location);
    $n = (int) http_app_db()->query("SELECT COUNT(*) c FROM quiz_attempts WHERE quiz_id = $quiz_id")->fetch_assoc()['c'];
    assert_same(0, $n, 'no attempt was created');

    // Enrolled in one of the teacher's sections: the quiz (assigned to no
    // section) is now open to them.
    $db = http_app_db();
    $db->query("INSERT INTO sections (teacher_email, name) VALUES ('teacher@http-test.local', 'HTTP Test Flow')");
    $sid = (int) $db->insert_id;
    $db->query("INSERT INTO section_students (section_id, student_email) VALUES ($sid, 'student@http-test.local')");
    assert_contains('HTTP Flow Quiz', $s->get('dashboards/studentPages/quizzes.php')->body);
});

test('student takes the quiz: autosave needs the token, submit is graded once', function () {
    $s = $GLOBALS['flow']['student'] ?? fail('previous test did not run');
    $quiz_id = $GLOBALS['flow']['quiz_id'];

    $s->get("dashboards/studentPages/take_quiz.php?quiz_id=$quiz_id&start=1"); // creates the attempt
    assert_same(302, $s->status);
    $page = $s->get("dashboards/studentPages/take_quiz.php?quiz_id=$quiz_id")->body;
    assert_same(200, $s->status);

    preg_match('/name="attempt_id" value="(\d+)"/', $page, $m) || fail('no attempt id');
    $attempt_id = (int) $m[1];
    $token = $s->csrf();
    // Exam mode: nothing of the questions is in the page before the start gate.
    assert_not_contains('2 + 2 = ?', $page, 'question text withheld at the gate');
    assert_not_contains('Explain gravity.', $page);
    $page = http_begin_exam($s, $attempt_id, $token);
    assert_contains('2 + 2 = ?', $page, 'questions arrive after Start');
    preg_match_all('/name="(q_\d+)" value="(\d+)"/', $page, $opts, PREG_SET_ORDER);
    preg_match('/<textarea[^>]*name="(q_\d+)"/', $page, $ta) || fail('no short-answer field');

    // Find the option whose label is "4" (the correct one).
    $db = http_app_db();
    $correct = $db->query(
        "SELECT o.id, o.question_id FROM question_options o JOIN questions q ON q.id = o.question_id
         WHERE q.quiz_id = $quiz_id AND o.is_correct = 1"
    )->fetch_assoc();
    $mcq_field = 'q_' . $correct['question_id'];

    $payload = ['attempt_id' => $attempt_id, 'answers' => [$mcq_field => (string) $correct['id']]];
    $s->post('dashboards/studentPages/save_answer.php', json_encode($payload), 'application/json');
    assert_same(403, $s->status, 'autosave without token');

    $s->post('dashboards/studentPages/save_answer.php', json_encode($payload + ['csrf_token' => $token]), 'application/json');
    assert_same(200, $s->status);
    assert_contains('"ok":true', $s->body);

    $form = ['csrf_token' => $token, 'action' => 'submit_attempt', 'quiz_id' => $quiz_id, 'attempt_id' => $attempt_id,
             $mcq_field => (string) $correct['id'], $ta[1] => 'Mass attracts mass.'];
    $s->post('dashboards/studentPages/take_quiz.php', $form);
    assert_same(302, $s->status);
    assert_contains("view_attempt.php?attempt_id=$attempt_id", $s->location);

    // Double submit (e.g. back button + resubmit) must not duplicate answers.
    $s->post('dashboards/studentPages/take_quiz.php', $form);
    $count = (int) $db->query("SELECT COUNT(*) c FROM attempt_answers WHERE attempt_id = $attempt_id")->fetch_assoc()['c'];
    assert_same(2, $count, 'one answer row per question');

    $row = $db->query("SELECT status, total_score, needs_manual_grading FROM quiz_attempts WHERE id = $attempt_id")->fetch_assoc();
    assert_same('submitted', $row['status']);
    assert_same('2.00', $row['total_score']);
    assert_same('1', (string) $row['needs_manual_grading']);

    assert_same(200, $s->get("dashboards/studentPages/view_attempt.php?attempt_id=$attempt_id")->status);
    assert_same(200, $s->get('dashboards/studentPages/results.php')->status);

    // Attempts are limited to 1: opening the quiz again goes back to the list.
    $s->get("dashboards/studentPages/take_quiz.php?quiz_id=$quiz_id");
    assert_contains('quizzes.php', $s->location);

    $GLOBALS['flow']['attempt_id'] = $attempt_id;
});

test('teacher grades the short answer and exports CSV', function () {
    $t = $GLOBALS['flow']['teacher'] ?? fail('previous test did not run');
    $attempt_id = $GLOBALS['flow']['attempt_id'] ?? fail('no attempt');
    $quiz_id = $GLOBALS['flow']['quiz_id'];

    $page = "dashboards/teachersPages/grade_attempt.php?attempt_id=$attempt_id";
    $token = $t->get($page)->csrf();
    preg_match_all('/name="score\[(\d+)\]"/', $t->body, $m);
    assert_same(2, count($m[1]), 'a score field per answer');

    $db = http_app_db();
    $pending = $db->query("SELECT id FROM attempt_answers WHERE attempt_id = $attempt_id AND points_awarded IS NULL")->fetch_assoc();
    $mcq = $db->query("SELECT id FROM attempt_answers WHERE attempt_id = $attempt_id AND points_awarded IS NOT NULL")->fetch_assoc();

    $t->post($page, [
        'csrf_token' => $token, 'action' => 'save_grades', 'attempt_id' => $attempt_id,
        'score' => [$pending['id'] => '99', $mcq['id'] => '2'],   // 99 must be clamped to 3
        'feedback' => [$pending['id'] => 'Good start.'],
    ]);
    // Saved, then redirected back (a refresh cannot re-submit the grades).
    assert_same(302, $t->status);
    assert_contains('grade_attempt.php?attempt_id=' . $attempt_id, $t->location);
    assert_contains('fully graded', $t->get($page)->body);

    $row = $db->query("SELECT total_score, needs_manual_grading FROM quiz_attempts WHERE id = $attempt_id")->fetch_assoc();
    assert_same('5.00', $row['total_score'], '2 (MCQ) + 3 (clamped)');
    assert_same('0', (string) $row['needs_manual_grading']);

    $csv = $t->get("dashboards/teachersPages/export_results.php?quiz_id=$quiz_id")->body;
    assert_same(200, $t->status);
    assert_contains('Student,Email,"Student ID",Section,Attempt', $csv);
    assert_contains('student@http-test.local', $csv);
});

test('results stay hidden from the student until the quiz closes or the teacher releases them', function () {
    $s = $GLOBALS['flow']['student'] ?? fail('previous test did not run');
    $t = $GLOBALS['flow']['teacher'];
    $attempt_id = $GLOBALS['flow']['attempt_id'];
    $quiz_id = $GLOBALS['flow']['quiz_id'];
    $view = "dashboards/studentPages/view_attempt.php?attempt_id=$attempt_id";

    // New quizzes default to "when the quiz closes"; it is still open.
    $body = $s->get($view)->body;
    assert_contains('Results will be available when your teacher closes the quiz.', $body);
    assert_not_contains('Good start.', $body, 'feedback hidden');
    assert_not_contains('tag-ok', $body, 'answer key hidden');
    assert_contains('Results not released', $s->get('dashboards/studentPages/results.php')->body);

    $token = $t->get('dashboards/teachersPages/setQuiz.php')->csrf();
    $t->post('dashboards/teachersPages/setQuiz.php', ['csrf_token' => $token, 'action' => 'release_results', 'quiz_id' => $quiz_id, 'release' => '1']);
    assert_contains('Results released to students.', $t->body);

    $body = $s->get($view)->body;
    assert_contains('Good start.', $body);
    assert_contains('tag tag-ok">correct', $body, 'answer key shown');
    assert_contains('5 / 5', $body);
});

test('a teacher cannot see or change another teacher\'s quiz, results or students', function () {
    $quiz_id = $GLOBALS['flow']['quiz_id'] ?? fail('previous test did not run');
    $other = http_register_and_login('teacher', 'other-teacher@http-test.local');

    $other->get("dashboards/teachersPages/quiz_questions.php?quiz_id=$quiz_id");
    assert_same(302, $other->status);
    $other->get("dashboards/teachersPages/grade_attempt.php?attempt_id=" . $GLOBALS['flow']['attempt_id']);
    assert_same(302, $other->status);
    $other->get("dashboards/teachersPages/export_results.php?quiz_id=$quiz_id");
    assert_same(302, $other->status);
    $other->get("dashboards/teachersPages/results.php?quiz_id=$quiz_id");
    assert_same(302, $other->status);

    assert_not_contains('HTTP Flow Quiz', $other->get('dashboards/teachersPages/setQuiz.php')->body);
    assert_not_contains('HTTP Flow Quiz', $other->get('dashboards/teachersPages/results.php')->body);
    assert_not_contains('student@http-test.local', $other->get('dashboards/teachersPages/student_profile.php')->body);
    assert_contains('not in your classes', $other->get('dashboards/teachersPages/student_profile.php?email=' . urlencode('student@http-test.local'))->body);

    // Changing it by forging a request does nothing.
    $token = $other->get('dashboards/teachersPages/setQuiz.php')->csrf();
    $other->post('dashboards/teachersPages/setQuiz.php', ['csrf_token' => $token, 'action' => 'delete_quiz', 'quiz_id' => $quiz_id]);
    assert_true(http_app_db()->query("SELECT id FROM quizzes WHERE id = $quiz_id")->fetch_assoc() !== null, 'quiz still exists');
});

test('every main page renders without PHP errors', function () {
    $t = $GLOBALS['flow']['teacher'] ?? fail('previous test did not run');
    $s = $GLOBALS['flow']['student'];
    foreach (['dashboards/teacherDashboard.php', 'dashboards/teachersPages/setQuiz.php', 'dashboards/teachersPages/results.php',
              'dashboards/teachersPages/results.php?quiz_id=' . $GLOBALS['flow']['quiz_id'],
              'dashboards/teachersPages/student_profile.php', 'dashboards/teachersPages/classes.php',
              'dashboards/settings.php'] as $p) {
        assert_same(200, $t->get($p)->status, $p);
    }
    foreach (['dashboards/studentDashboard.php', 'dashboards/studentPages/quizzes.php', 'dashboards/studentPages/results.php', 'dashboards/settings.php'] as $p) {
        assert_same(200, $s->get($p)->status, $p);
    }
    foreach (['auth/login.php', 'auth/registration.php', 'auth/forget_password.php'] as $p) {
        assert_same(200, (new Browser(http_base()))->get($p)->status, $p);
    }
});

/** Insert a published one-question quiz for the HTTP teacher directly. */
function http_make_quiz(string $title, int $lock): int
{
    $db = http_app_db();
    $teacher = 'teacher@http-test.local';
    $stmt = $db->prepare("INSERT INTO quizzes (teacher_email, title, status, max_attempts, lock_on_leave) VALUES (?, ?, 'published', 2, ?)");
    $stmt->bind_param("ssi", $teacher, $title, $lock);
    $stmt->execute();
    $quiz_id = (int) $stmt->insert_id;
    $db->query("INSERT INTO questions (quiz_id, type, question_text, points, order_index) VALUES ($quiz_id, 'mcq', 'Pick A', 1, 0)");
    $qid = (int) $db->insert_id;
    $db->query("INSERT INTO question_options (question_id, option_text, is_correct, order_index) VALUES ($qid, 'A', 1, 0), ($qid, 'B', 0, 1)");
    return $quiz_id;
}

test('lock mode: leaving the page submits the attempt as left_page', function () {
    $s = $GLOBALS['flow']['student'] ?? fail('previous test did not run');
    $quiz_id = http_make_quiz('Locked quiz', 1);

    // Without start=1 no attempt is created (protects against reload after auto-submit).
    $s->get("dashboards/studentPages/take_quiz.php?quiz_id=$quiz_id");
    assert_contains('quizzes.php', $s->location);

    $s->get("dashboards/studentPages/take_quiz.php?quiz_id=$quiz_id&start=1");
    $page = $s->get("dashboards/studentPages/take_quiz.php?quiz_id=$quiz_id")->body;
    assert_contains('Locked quiz.', $page, 'student is warned');
    assert_contains('const LOCKED = true', $page);
    preg_match('/name="attempt_id" value="(\d+)"/', $page, $m) || fail('no attempt id');
    $attempt_id = (int) $m[1];
    $token = $s->csrf();
    assert_not_contains('Pick A', $page, 'question withheld at the gate');
    $questions = http_begin_exam($s, $attempt_id, $token);
    preg_match('/name="(q_\d+)" value="(\d+)"/', $questions, $opt) || fail('no option');

    $payload = ['attempt_id' => $attempt_id, 'answers' => [$opt[1] => $opt[2]]];
    $s->post('dashboards/studentPages/leave_quiz.php', json_encode($payload), 'application/json');
    assert_same(403, $s->status, 'beacon without token');

    $s->post('dashboards/studentPages/leave_quiz.php', json_encode($payload + ['csrf_token' => $token]), 'application/json');
    assert_same(200, $s->status);
    assert_contains('"closed":true', $s->body);

    $row = http_app_db()->query("SELECT status, submit_reason FROM quiz_attempts WHERE id = $attempt_id")->fetch_assoc();
    assert_same('submitted', $row['status']);
    assert_same('left_page', $row['submit_reason']);

    $s->post('dashboards/studentPages/leave_quiz.php', json_encode($payload + ['csrf_token' => $token]), 'application/json');
    assert_same(409, $s->status, 'second beacon is ignored');

    // Reloading the quiz page does not start a new attempt.
    $s->get("dashboards/studentPages/take_quiz.php?quiz_id=$quiz_id");
    assert_contains('quizzes.php', $s->location);

    assert_contains('You left the quiz page', $s->get("dashboards/studentPages/view_attempt.php?attempt_id=$attempt_id")->body);
    $t = $GLOBALS['flow']['teacher'];
    assert_contains('left the quiz page', $t->get("dashboards/teachersPages/grade_attempt.php?attempt_id=$attempt_id")->body);
});

test('lock mode off: the leave endpoint refuses and the quiz stays open', function () {
    $s = $GLOBALS['flow']['student'] ?? fail('previous test did not run');
    $quiz_id = http_make_quiz('Unlocked quiz', 0);

    $s->get("dashboards/studentPages/take_quiz.php?quiz_id=$quiz_id&start=1");
    $page = $s->get("dashboards/studentPages/take_quiz.php?quiz_id=$quiz_id")->body;
    assert_contains('const LOCKED = false', $page);
    assert_not_contains('Locked quiz.', $page);
    preg_match('/name="attempt_id" value="(\d+)"/', $page, $m) || fail('no attempt id');

    $s->post('dashboards/studentPages/leave_quiz.php', json_encode(['attempt_id' => (int) $m[1], 'csrf_token' => $s->csrf()]), 'application/json');
    assert_same(409, $s->status);
    assert_contains('not_locked', $s->body);
});

test('teacher can turn lock mode off in quiz settings', function () {
    $t = $GLOBALS['flow']['teacher'] ?? fail('previous test did not run');
    $quiz_id = $GLOBALS['flow']['quiz_id'];
    $token = $t->get('dashboards/teachersPages/setQuiz.php')->csrf();
    assert_contains('name="lock_on_leave"', $t->body);

    $t->post('dashboards/teachersPages/setQuiz.php', [
        'csrf_token' => $token, 'action' => 'update_settings', 'quiz_id' => $quiz_id, 'title' => 'HTTP Flow Quiz', 'max_attempts' => '1',
    ]); // checkbox omitted = off
    assert_contains('Quiz settings saved.', $t->body);
    $row = http_app_db()->query("SELECT lock_on_leave FROM quizzes WHERE id = $quiz_id")->fetch_assoc();
    assert_same('0', (string) $row['lock_on_leave']);
});

test('logout ends the session', function () {
    $s = $GLOBALS['flow']['student'] ?? fail('previous test did not run');
    // A plain visit (e.g. a link from another site) does not log out.
    $s->get('auth/logout.php');
    assert_same(200, $s->status);
    $s->get('dashboards/studentDashboard.php');
    assert_same(200, $s->status, 'still logged in after a GET');

    $s->post('auth/logout.php', []);
    $s->get('dashboards/studentDashboard.php');
    assert_same(302, $s->status);
    assert_contains('login.php', $s->location);
});

test('teardown: remove HTTP test data', function () {
    http_cleanup();
    $n = (int) http_app_db()->query("SELECT COUNT(*) c FROM reg_info WHERE email LIKE '%@http-test.local'")->fetch_assoc()['c'];
    assert_same(0, $n);
});
