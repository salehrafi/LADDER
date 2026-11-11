<?php
/**
 * Classes end to end: a teacher imports students from a CSV upload, a new
 * student logs in with the default password and must change it, a quiz
 * limited to a section, the teacher managing their students, and a second
 * teacher who must not see any of it. Uses only @http-test.local accounts and
 * "HTTP Test" sections, removed afterwards.
 */

require_once __DIR__ . '/support.php';

$GLOBALS['cls'] = [];

test('classes setup: two teachers', function () {
    http_cleanup();
    http_make_account('teacher', 'ct1@http-test.local', 'Class Teacher One');
    http_make_account('teacher', 'ct2@http-test.local', 'Class Teacher Two');
    $t = http_login('ct1@http-test.local');
    assert_contains('teacherDashboard.php', $t->location);
    $GLOBALS['cls']['t1'] = $t;
    $GLOBALS['cls']['t2'] = http_login('ct2@http-test.local');
});

test('teacher imports students from a CSV upload', function () {
    $t = $GLOBALS['cls']['t1'] ?? fail('setup failed');
    $token = $t->get('dashboards/teachersPages/classes.php')->csrf();

    $csv = tempnam(sys_get_temp_dir(), 'csv') . '.csv';
    file_put_contents($csv, "name,email,id,section\nCsv One,csv1@http-test.local,HTTP-1,HTTP Test A\nCsv Two,csv2@http-test.local,HTTP-2,HTTP Test B\nBad Row,not-an-email,,HTTP Test A\n");
    $t->post('dashboards/teachersPages/classes.php', [
        'csrf_token' => $token, 'action' => 'import_csv', 'default_password' => 'Start@123',
        'csv' => new CURLFile($csv, 'text/csv', 'students.csv'),
    ]);
    @unlink($csv);

    assert_same(200, $t->status);
    assert_contains('2 new account(s) created', $t->body);
    assert_contains('Line 4: Invalid email address.', $t->body);
    assert_contains('Start@123', $t->body, 'default password shown once');

    $sec = http_app_db()->query("SELECT id FROM sections WHERE name = 'HTTP Test A' AND teacher_email = 'ct1@http-test.local'")->fetch_assoc();
    assert_true($sec !== null, 'section created from the CSV');
    $GLOBALS['cls']['section_a'] = (int) $sec['id'];
});

test('another teacher sees none of it', function () {
    $t2 = $GLOBALS['cls']['t2'] ?? fail('setup failed');
    $sid = $GLOBALS['cls']['section_a'];

    assert_not_contains('HTTP Test A', $t2->get('dashboards/teachersPages/classes.php')->body);
    $t2->get("dashboards/teachersPages/classes.php?section_id=$sid");
    assert_same(302, $t2->status, "cannot open another teacher's section");
    assert_not_contains('csv1@http-test.local', $t2->get('dashboards/teachersPages/student_profile.php')->body);
    assert_contains('not in your classes', $t2->get('dashboards/teachersPages/student_profile.php?email=' . urlencode('csv1@http-test.local'))->body);

    // Forged requests change nothing.
    $token = $t2->get('dashboards/teachersPages/classes.php')->csrf();
    $t2->post('dashboards/teachersPages/student_profile.php', ['csrf_token' => $token, 'action' => 'reset_password', 'student_email' => 'csv1@http-test.local', 'default_password' => 'Hack@1234']);
    assert_contains('not in your classes', $t2->body);
    $t2->post('dashboards/teachersPages/student_profile.php', ['csrf_token' => $token, 'action' => 'delete_student', 'student_email' => 'csv1@http-test.local']);
    assert_true(http_app_db()->query("SELECT 1 FROM reg_info WHERE email = 'csv1@http-test.local'")->fetch_assoc() !== null, 'student not deleted');
    $t2->post('dashboards/teachersPages/classes.php', ['csrf_token' => $token, 'action' => 'delete_section', 'section_id' => $sid]);
    assert_true(http_app_db()->query("SELECT 1 FROM sections WHERE id = $sid")->fetch_assoc() !== null, 'section not deleted');
});

test('imported student must change the default password at first login', function () {
    $s = http_login('csv1@http-test.local', 'Start@123');
    assert_contains('first_login.php', $s->location);
    $s->get('dashboards/studentDashboard.php');
    assert_contains('first_login.php', $s->location, 'blocked until the password is changed');
    $token = $s->get('auth/first_login.php')->csrf();
    $s->post('auth/first_login.php', ['csrf_token' => $token, 'new_password' => 'Start@123', 'repeat_password' => 'Start@123']);
    assert_contains('not the default one', $s->body);
    $s->post('auth/first_login.php', ['csrf_token' => $token, 'new_password' => 'Mine@1234', 'repeat_password' => 'Mine@1234']);
    assert_contains('studentDashboard.php', $s->location);
    $GLOBALS['cls']['s1'] = $s;
});

test('teacher adds one student by hand to a section', function () {
    $t = $GLOBALS['cls']['t1'] ?? fail('setup failed');
    $sid = $GLOBALS['cls']['section_a'];
    $page = "dashboards/teachersPages/classes.php?section_id=$sid";
    $token = $t->get($page)->csrf();
    $t->post($page, ['csrf_token' => $token, 'action' => 'add_student', 'section_id' => $sid,
        'name' => 'Manual Student', 'email' => 'manual@http-test.local', 'student_id' => 'HTTP-3', 'default_password' => '']);
    assert_contains('Student registered and added to the section.', $t->body);
    assert_contains('manual@http-test.local', $t->body, 'shown in the roster');
});

test('a quiz limited to a section is hidden from other students', function () {
    $t = $GLOBALS['cls']['t1'] ?? fail('setup failed');
    $db = http_app_db();
    $db->query("INSERT INTO quizzes (teacher_email, title, status, max_attempts, lock_on_leave) VALUES ('ct1@http-test.local', 'HTTP Section Quiz', 'published', 1, 0)");
    $quiz_id = (int) $db->insert_id;
    $db->query("INSERT INTO questions (quiz_id, type, question_text, points, order_index) VALUES ($quiz_id, 'short_answer', 'Q', 1, 0)");

    $token = $t->get('dashboards/teachersPages/setQuiz.php')->csrf();
    $t->post('dashboards/teachersPages/setQuiz.php', ['csrf_token' => $token, 'action' => 'update_settings', 'quiz_id' => $quiz_id,
        'title' => 'HTTP Section Quiz', 'max_attempts' => '1', 'sections' => [$GLOBALS['cls']['section_a']]]);
    assert_contains('Quiz settings saved.', $t->body);

    $in = $GLOBALS['cls']['s1'];  // csv1 is in HTTP Test A
    assert_contains('HTTP Section Quiz', $in->get('dashboards/studentPages/quizzes.php')->body);

    $out = http_login('csv2@http-test.local', 'Start@123');      // in HTTP Test B
    $token = $out->get('auth/first_login.php')->csrf();
    $out->post('auth/first_login.php', ['csrf_token' => $token, 'new_password' => 'Mine@5678', 'repeat_password' => 'Mine@5678']);
    assert_not_contains('HTTP Section Quiz', $out->get('dashboards/studentPages/quizzes.php')->body);
    $out->get("dashboards/studentPages/take_quiz.php?quiz_id=$quiz_id&start=1");
    assert_contains('quizzes.php', $out->location, 'cannot start it by URL either');
});

test('teacher edits a student: name, email, ID and sections', function () {
    $t = $GLOBALS['cls']['t1'] ?? fail('setup failed');
    $page = 'dashboards/teachersPages/student_profile.php?email=' . urlencode('manual@http-test.local');
    $token = $t->get($page)->csrf();
    $t->post($page, ['csrf_token' => $token, 'action' => 'update_student', 'student_email' => 'manual@http-test.local',
        'full_name' => 'Manual Renamed', 'email' => 'manual2@http-test.local', 'student_id' => 'HTTP-33',
        'sections' => [$GLOBALS['cls']['section_a']]]);
    assert_same(302, $t->status);
    assert_contains('manual2%40http-test.local', $t->location);

    $row = http_app_db()->query("SELECT full_name, id FROM reg_info WHERE email = 'manual2@http-test.local'")->fetch_assoc();
    assert_same(['full_name' => 'Manual Renamed', 'id' => 'HTTP-33'], $row);
});

test('teacher resets a student password and removes a student', function () {
    $t = $GLOBALS['cls']['t1'] ?? fail('setup failed');
    $page = 'dashboards/teachersPages/student_profile.php?email=' . urlencode('csv1@http-test.local');
    $token = $t->get($page)->csrf();
    $t->post($page, ['csrf_token' => $token, 'action' => 'reset_password', 'student_email' => 'csv1@http-test.local', 'default_password' => 'Again@123']);
    assert_contains('Again@123', $t->body);
    assert_contains('first_login.php', http_login('csv1@http-test.local', 'Again@123')->location);

    $page = 'dashboards/teachersPages/student_profile.php?email=' . urlencode('manual2@http-test.local');
    $token = $t->get($page)->csrf();
    $t->post($page, ['csrf_token' => $token, 'action' => 'delete_student', 'student_email' => 'manual2@http-test.local']);
    assert_contains('deleted=account', $t->location, 'only this teacher had the student, so the account goes');
    assert_same(null, http_app_db()->query("SELECT 1 FROM reg_info WHERE email = 'manual2@http-test.local'")->fetch_assoc());
});

test('the Teachers page and cross-teacher filters are gone', function () {
    $t = $GLOBALS['cls']['t1'] ?? fail('setup failed');
    $t->get('dashboards/teachersPages/teachers.php');
    assert_same(404, $t->status);
    assert_not_contains('teachers.php', $t->get('dashboards/teacherDashboard.php')->body, 'no nav link');
    assert_not_contains('All teachers', $t->get('dashboards/teachersPages/setQuiz.php')->body);
});

test('classes teardown', function () {
    http_cleanup();
    $db = http_app_db();
    assert_same(0, (int) $db->query("SELECT COUNT(*) c FROM reg_info WHERE email LIKE '%@http-test.local'")->fetch_assoc()['c']);
    assert_same(0, (int) $db->query("SELECT COUNT(*) c FROM sections WHERE name LIKE 'HTTP Test%'")->fetch_assoc()['c']);
});
