<?php
require_once APP_ROOT . '/includes/class_helpers.php';

test('dashboard_for_role() routes each role home', function () {
    assert_same('dashboards/teacherDashboard.php', dashboard_for_role('teacher'));
    assert_same('dashboards/studentDashboard.php', dashboard_for_role('student'));
    assert_same('auth/login.php', dashboard_for_role('admin'), 'there is no admin role');
});

test('password_rule_error() enforces 6-64 characters', function () {
    assert_same(null, password_rule_error('Abcdef12'));
    assert_same(null, password_rule_error('abc123'));
    assert_same(null, password_rule_error(str_repeat('a', 64)));
    assert_true(password_rule_error('short') !== null);
    assert_true(password_rule_error(str_repeat('a', 5)) !== null);
    assert_true(password_rule_error(str_repeat('a', 65)) !== null);
});

test('generate_default_password() is valid, readable and random', function () {
    $seen = [];
    for ($i = 0; $i < 50; $i++) {
        $p = generate_default_password();
        assert_same(null, password_rule_error($p));
        assert_true((bool) preg_match('/[A-Z]/', $p) && (bool) preg_match('/[a-z]/', $p) && (bool) preg_match('/[0-9]/', $p), "mixed classes: $p");
        assert_same(0, preg_match('/[0O1lI]/', $p), "no ambiguous characters: $p");
        $seen[$p] = true;
    }
    assert_same(50, count($seen), 'passwords should not repeat');
});

test('parse_student_csv() reads a header in any order, BOM and CRLF', function () {
    $csv = "\xEF\xBB\xBFSection,Email,Name,Student ID\r\nCSE A,RAFI@X.EDU,Rafi Ahmed,2022-1\r\n\r\nCSE B,nusrat@x.edu,Nusrat Khan,\r\n";
    $r = parse_student_csv($csv);
    assert_same([], $r['errors']);
    assert_same(2, count($r['rows']));
    assert_same(['line' => 2, 'name' => 'Rafi Ahmed', 'email' => 'rafi@x.edu', 'id' => '2022-1', 'section' => 'CSE A'], $r['rows'][0]);
    assert_same('', $r['rows'][1]['id']);
});

test('parse_student_csv() accepts no header and semicolons', function () {
    $r = parse_student_csv("Rafi Ahmed;rafi@x.edu;S1;Sec A\nNusrat;nusrat@x.edu;S2;Sec B");
    assert_same(2, count($r['rows']));
    assert_same(1, $r['rows'][0]['line']);
    assert_same('Sec B', $r['rows'][1]['section']);
});

test('parse_student_csv() reports empty files and missing columns', function () {
    assert_contains('empty', parse_student_csv("  \n ")['errors'][0]);
    assert_contains('"name" and "email"', parse_student_csv("name,section\nA,B")['errors'][0]);
});

test('clean_section_name() trims and collapses spaces', function () {
    assert_same('CSE 101 A', clean_section_name("  CSE   101\tA "));
});

test('account_field_error() validates name, email and ID', function () {
    assert_same(null, account_field_error('Rafi', 'rafi@x.edu', ''));
    assert_true(account_field_error('', 'rafi@x.edu', '') !== null);
    assert_true(account_field_error('Rafi', 'nope', '') !== null);
    assert_true(account_field_error('Rafi', 'rafi@x.edu', str_repeat('9', 51)) !== null);
});
