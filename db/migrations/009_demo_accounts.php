<?php
/**
 * Public demo accounts, so visitors can explore LADDER without signing up
 * (see DEMO_ACCOUNTS in includes/helpers.php):
 *
 *   Teacher: teacher@gmail.com / 1234
 *   Student: student@gmail.com / 1234, enrolled in the demo teacher's class
 *
 * They are flagged is_demo: their password, name and notification settings
 * cannot be changed, they get no reset codes, and no teacher can take them
 * over. Run `php db/seed_demo.php` to fill the demo class with quizzes and results.
 */
require_once APP_ROOT . '/includes/helpers.php'; // ensure_demo_accounts(), also from CLI scripts

return function (mysqli $conn): void {
    ensure_column($conn, 'reg_info', 'is_demo', 'TINYINT(1) NOT NULL DEFAULT 0');
    ensure_demo_accounts($conn);
};
