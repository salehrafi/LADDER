<?php
// The public demo accounts: they sign in with the advertised password and
// cannot be changed or reset by visitors.

require_once __DIR__ . '/support.php';

test('demo student and teacher sign in with the password shown on the sign-in page', function () {
    $page = (new Browser(http_base()))->get('auth/login.php')->body;
    assert_contains(DEMO_STUDENT_EMAIL, $page);
    assert_contains('Demo teacher', $page);

    $s = http_login(DEMO_STUDENT_EMAIL, DEMO_PASSWORD);
    assert_same(302, $s->status);
    assert_contains('studentDashboard.php', $s->location);

    $t = http_login(DEMO_TEACHER_EMAIL, DEMO_PASSWORD);
    assert_same(302, $t->status);
    assert_contains('teacherDashboard.php', $t->location);
});

test('demo accounts cannot change their settings or get reset codes', function () {
    $s = http_login(DEMO_STUDENT_EMAIL, DEMO_PASSWORD);
    $token = $s->get('dashboards/settings.php')->csrf();
    $s->post('dashboards/settings.php', ['csrf_token' => $token, 'form' => 'password',
        'current_password' => DEMO_PASSWORD, 'new_password' => 'Taken@123', 'repeat_password' => 'Taken@123']);
    assert_contains('shared demo account', $s->body);
    assert_same(302, http_login(DEMO_STUDENT_EMAIL, DEMO_PASSWORD)->status, 'password unchanged');

    (new Browser(http_base()))->post('auth/forget_password.php', ['mailTo' => DEMO_TEACHER_EMAIL]);
    $row = http_app_db()->query("SELECT otp FROM reg_info WHERE email = '" . DEMO_TEACHER_EMAIL . "'")->fetch_assoc();
    assert_same(null, $row['otp'], 'no reset code issued');
    http_app_db()->query("DELETE FROM auth_throttle WHERE subject = '" . DEMO_TEACHER_EMAIL . "'");
});
