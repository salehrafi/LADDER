<?php
// Each teacher's own classes: sections, student import, student management,
// isolation between teachers, and section-based quiz visibility.
// Runs on the isolated test database.
require_once APP_ROOT . '/includes/class_helpers.php';

const T1 = 't1@test.local';
const T2 = 't2@test.local';

function classes_fixture(): mysqli
{
    reset_test_db();
    $conn = test_db();
    foreach ([[T1, 'teacher'], [T2, 'teacher'], ['old@test.local', 'student']] as [$email, $role]) {
        $hash = password_hash('Original@1', PASSWORD_DEFAULT);
        $stmt = $conn->prepare("INSERT INTO reg_info (full_name, email, pass, role) VALUES ('User', ?, ?, ?)");
        $stmt->bind_param("sss", $email, $hash, $role);
        $stmt->execute();
    }
    $conn->query("TRUNCATE TABLE section_students");
    $conn->query("DELETE FROM quiz_assignments");
    $conn->query("DELETE FROM sections");
    return $conn;
}

function user_pass_ok(mysqli $conn, string $email, string $password): bool
{
    $row = $conn->query("SELECT pass FROM reg_info WHERE email = '" . $conn->real_escape_string($email) . "'")->fetch_assoc();
    return $row && password_verify($password, $row['pass']);
}

function import_one(mysqli $conn, string $teacher, string $email, string $section, string $id = ''): array
{
    return import_students($conn, $teacher, [['line' => 1, 'name' => 'Student ' . $email, 'email' => $email, 'id' => $id, 'section' => $section]], 'Default@1');
}

test('import_students() registers new students with a default password and enrols them', function () {
    $conn = classes_fixture();
    $r = import_students($conn, T1, [
        ['line' => 2, 'name' => 'Rafi Ahmed', 'email' => 'rafi@test.local', 'id' => 'S1', 'section' => 'CSE A'],
        ['line' => 3, 'name' => 'Nusrat Khan', 'email' => 'nusrat@test.local', 'id' => '', 'section' => 'cse a'],
        ['line' => 4, 'name' => 'Old Student', 'email' => 'old@test.local', 'id' => '', 'section' => 'CSE B'],
    ], 'Default@1');

    assert_same(2, $r['created']);
    assert_same(3, $r['enrolled']);
    assert_same([], $r['skipped']);
    assert_true(user_pass_ok($conn, 'rafi@test.local', 'Default@1'));
    assert_true(user_pass_ok($conn, 'old@test.local', 'Original@1'), 'existing students keep their password');
    $row = $conn->query("SELECT must_change_password, id FROM reg_info WHERE email = 'rafi@test.local'")->fetch_assoc();
    assert_same('1', (string) $row['must_change_password']);
    assert_same('S1', $row['id']);
    assert_same(2, count(teacher_sections($conn, T1)), "'CSE A' and 'cse a' are the same section");
});

test('import_students() skips bad rows with a reason and keeps the good ones', function () {
    $conn = classes_fixture();
    $conn->query("UPDATE reg_info SET id = 'TAKEN' WHERE email = 'old@test.local'");
    $r = import_students($conn, T1, [
        ['line' => 2, 'name' => '', 'email' => 'a@test.local', 'id' => '', 'section' => 'X'],
        ['line' => 3, 'name' => 'B', 'email' => 'not-an-email', 'id' => '', 'section' => 'X'],
        ['line' => 4, 'name' => 'C', 'email' => T2, 'id' => '', 'section' => 'X'],
        ['line' => 5, 'name' => 'D', 'email' => 'd@test.local', 'id' => 'TAKEN', 'section' => 'X'],
        ['line' => 6, 'name' => 'E', 'email' => 'e@test.local', 'id' => '', 'section' => ''],
        ['line' => 7, 'name' => 'F', 'email' => 'f@test.local', 'id' => '', 'section' => 'X'],
        ['line' => 8, 'name' => 'F again', 'email' => 'F@test.local', 'id' => '', 'section' => 'X'],
    ], 'Default@1');

    assert_same(1, $r['created']);
    assert_same([2, 3, 4, 5, 6, 8], array_keys($r['skipped']));
    assert_contains('teacher account', $r['skipped'][4]);
    assert_contains('already used', $r['skipped'][5]);
    assert_contains('No section', $r['skipped'][6]);
    assert_contains('Duplicate of line 7', $r['skipped'][8]);
});

test('import_students() uses only the teacher\'s own fallback section', function () {
    $conn = classes_fixture();
    $mine = ensure_section($conn, T1, 'Fallback');
    $theirs = ensure_section($conn, T2, 'Theirs');

    $r = import_students($conn, T1, [['line' => 1, 'name' => 'G', 'email' => 'g@test.local', 'id' => '', 'section' => '']], 'Default@1', $mine);
    assert_same(1, $r['enrolled']);

    $r = import_students($conn, T1, [['line' => 1, 'name' => 'H', 'email' => 'h@test.local', 'id' => '', 'section' => '']], 'Default@1', $theirs);
    assert_contains('No section', $r['skipped'][1], "another teacher's section is never used");
});

test('sections are private: same name, separate sections, no cross-teacher changes', function () {
    $conn = classes_fixture();
    $a = ensure_section($conn, T1, 'Section A');
    $b = ensure_section($conn, T2, 'Section A');
    assert_true($a !== $b, 'each teacher has their own "Section A"');
    assert_same(1, count(teacher_sections($conn, T1)));
    assert_same(null, find_teacher_section($conn, T2, $a));

    import_one($conn, T1, 'k@test.local', 'Section A');
    assert_false(delete_section($conn, T2, $a), 'T2 cannot delete T1\'s section');
    assert_false(remove_from_section($conn, T2, $a, 'k@test.local'), 'T2 cannot change T1\'s roster');
    rename_section($conn, T2, $a, 'Hijacked');
    assert_same('Section A', find_teacher_section($conn, T1, $a)['name'], 'T2 cannot rename it');

    rename_section($conn, T1, $a, 'Renamed');
    assert_same('Renamed', find_teacher_section($conn, T1, $a)['name']);
    assert_true(remove_from_section($conn, T1, $a, 'k@test.local'));
    assert_true(delete_section($conn, T1, $a));
    assert_true(find_user($conn, 'k@test.local') !== null, 'deleting a section keeps the account');
});

test('a teacher only manages their own students', function () {
    $conn = classes_fixture();
    import_one($conn, T1, 'mine@test.local', 'A');

    assert_true(teacher_has_student($conn, T1, 'mine@test.local'));
    assert_false(teacher_has_student($conn, T2, 'mine@test.local'));
    assert_false(reset_student_password($conn, T2, 'mine@test.local', 'Hack@1234'));
    assert_true(user_pass_ok($conn, 'mine@test.local', 'Default@1'), 'password untouched by T2');
    assert_same('not_found', remove_student($conn, T2, 'mine@test.local'));
    try {
        update_student($conn, T2, 'mine@test.local', 'X', 'x@test.local', '');
        fail('expected refusal');
    } catch (InvalidArgumentException $e) {
        assert_contains('Only the teacher who created', $e->getMessage());
    }

    assert_true(reset_student_password($conn, T1, 'mine@test.local', 'Reset@123'));
    assert_true(user_pass_ok($conn, 'mine@test.local', 'Reset@123'));
    assert_same('1', (string) find_user($conn, 'mine@test.local')['must_change_password']);
});

test('enrolling someone else\'s student gives no control over their account', function () {
    // Anyone can sign up as a teacher, so enrolling a stranger's email must
    // not allow a password reset, an email change or deleting the account.
    $conn = classes_fixture();
    import_one($conn, T1, 'victim@test.local', 'A');             // T1 created it
    $r = import_one($conn, T2, 'victim@test.local', 'Mine');      // T2 enrols it
    assert_same(0, $r['created']);
    assert_true(teacher_has_student($conn, T2, 'victim@test.local'));
    assert_false(teacher_manages_account($conn, T2, 'victim@test.local'));
    assert_true(teacher_manages_account($conn, T1, 'victim@test.local'));

    assert_false(reset_student_password($conn, T2, 'victim@test.local', 'Hack@1234'));
    assert_true(user_pass_ok($conn, 'victim@test.local', 'Default@1'), 'password untouched');
    try {
        update_student($conn, T2, 'victim@test.local', 'X', 'attacker@test.local', '');
        fail('expected refusal');
    } catch (InvalidArgumentException $e) {
        assert_contains('Only the teacher who created', $e->getMessage());
    }
    assert_true(find_user($conn, 'victim@test.local') !== null, 'email unchanged');

    // A self-registered student (no creator) is managed by nobody.
    import_one($conn, T2, 'old@test.local', 'Mine');
    assert_false(reset_student_password($conn, T2, 'old@test.local', 'Hack@1234'));
    assert_same('removed', remove_student($conn, T2, 'old@test.local'));
    assert_true(find_user($conn, 'old@test.local') !== null, 'a self-registered account is never deleted by a teacher');
    assert_true(user_pass_ok($conn, 'old@test.local', 'Original@1'));
});

test('taking a teacher\'s quiz makes the student theirs (results access)', function () {
    $conn = classes_fixture();
    $quiz = make_quiz($conn, [['mcq', 'Q', 1, null, ['a' => true]]], ['teacher' => T2]);
    finalize_attempt($conn, make_attempt($conn, $quiz, 'old@test.local'), []);
    assert_true(teacher_has_student($conn, T2, 'old@test.local'));
    assert_false(teacher_has_student($conn, T1, 'old@test.local'));
});

test('update_student() renames, carries a new email through, and refuses taken emails', function () {
    $conn = classes_fixture();
    import_one($conn, T1, 'i@test.local', 'S', 'I1');
    $quiz = make_quiz($conn, [['mcq', 'Q', 1, null, ['a' => true, 'b' => false]]], ['teacher' => T1]);
    finalize_attempt($conn, make_attempt($conn, $quiz, 'i@test.local'), []);

    update_student($conn, T1, 'i@test.local', 'Ivy', 'ivy@test.local', 'I2');
    assert_same(null, find_user($conn, 'i@test.local'));
    assert_same('Ivy', find_user($conn, 'ivy@test.local')['full_name']);
    assert_same(1, (int) $conn->query("SELECT COUNT(*) c FROM quiz_attempts WHERE student_email = 'ivy@test.local'")->fetch_assoc()['c']);
    assert_same(1, count(teacher_student_section_ids($conn, T1, 'ivy@test.local')));

    try {
        update_student($conn, T1, 'ivy@test.local', 'Ivy', 'old@test.local', '');
        fail('expected a duplicate error');
    } catch (InvalidArgumentException $e) {
        assert_contains('already used', $e->getMessage());
    }
});

test('set_student_sections() only touches the teacher\'s own sections', function () {
    $conn = classes_fixture();
    import_one($conn, T1, 'both@test.local', 'T1 Sec');
    import_one($conn, T2, 'both@test.local', 'T2 Sec');
    $t1b = ensure_section($conn, T1, 'T1 Other');
    $t2 = ensure_section($conn, T2, 'T2 Sec');

    set_student_sections($conn, T1, 'both@test.local', [$t1b, $t2]);   // $t2 is not T1's: ignored
    assert_same([$t1b], teacher_student_section_ids($conn, T1, 'both@test.local'));
    assert_same([$t2], teacher_student_section_ids($conn, T2, 'both@test.local'), "T2's enrolment is untouched");
    assert_same(['both@test.local' => 'T1 Other'], section_names_by_student($conn, T1));
});

test('remove_student() deletes the account only if no other teacher has the student', function () {
    $conn = classes_fixture();
    import_one($conn, T1, 'solo@test.local', 'A');
    import_one($conn, T1, 'shared@test.local', 'A');
    import_one($conn, T2, 'shared@test.local', 'B');
    $quiz = make_quiz($conn, [['mcq', 'Q', 1, null, ['a' => true]]], ['teacher' => T1]);
    finalize_attempt($conn, make_attempt($conn, $quiz, 'shared@test.local'), []);

    assert_same('deleted', remove_student($conn, T1, 'solo@test.local'));
    assert_same(null, find_user($conn, 'solo@test.local'));

    assert_same('removed', remove_student($conn, T1, 'shared@test.local'));
    assert_true(find_user($conn, 'shared@test.local') !== null, 'kept for the other teacher');
    assert_same([], teacher_student_section_ids($conn, T1, 'shared@test.local'));
    assert_same(1, count(teacher_student_section_ids($conn, T2, 'shared@test.local')));
    assert_same(0, (int) $conn->query("SELECT COUNT(*) c FROM quiz_attempts WHERE student_email = 'shared@test.local'")->fetch_assoc()['c'], "T1's results deleted");
});

test('quizzes assigned to sections are only visible to those students', function () {
    $conn = classes_fixture();
    import_one($conn, T1, 'in@test.local', 'Sec A');
    import_one($conn, T1, 'out@test.local', 'Sec B');
    $theirs = ensure_section($conn, T2, 'T2 Sec');
    $open = make_quiz($conn, [['mcq', 'Q', 1, null, ['a' => true]]], ['teacher' => T1]);
    $limited = make_quiz($conn, [['mcq', 'Q', 1, null, ['a' => true]]], ['teacher' => T1]);
    set_quiz_sections($conn, T1, $limited['quiz_id'], [ensure_section($conn, T1, 'Sec A'), $theirs, 99999]);

    assert_same(1, count(quiz_section_ids($conn, $limited['quiz_id'])), "other teachers' and unknown sections are ignored");
    assert_true(quiz_visible_to_student($conn, $open['quiz_id'], 'out@test.local'), 'no sections = every student in my sections');
    // Not one of this teacher's students: never visible, assigned or not.
    ensure_test_account($conn, 'loner@test.local', 'student');
    import_one($conn, T2, 'theirs@test.local', 'T2 Sec');
    assert_false(quiz_visible_to_student($conn, $open['quiz_id'], 'loner@test.local'), 'student in no section');
    assert_false(quiz_visible_to_student($conn, $open['quiz_id'], 'theirs@test.local'), "another teacher's student");
    assert_true(quiz_visible_to_student($conn, $limited['quiz_id'], 'in@test.local'));
    assert_false(quiz_visible_to_student($conn, $limited['quiz_id'], 'out@test.local'));

    set_quiz_sections($conn, T1, $limited['quiz_id'], []);
    assert_true(quiz_visible_to_student($conn, $limited['quiz_id'], 'out@test.local'), 'clearing sections opens it to all my students');
});

test('complete_password_change() stores the new password and clears the flag', function () {
    $conn = classes_fixture();
    import_one($conn, T1, 'p@test.local', 'A');
    complete_password_change($conn, 'p@test.local', 'MyOwn@123');
    assert_true(user_pass_ok($conn, 'p@test.local', 'MyOwn@123'));
    assert_same('0', (string) find_user($conn, 'p@test.local')['must_change_password']);
});
