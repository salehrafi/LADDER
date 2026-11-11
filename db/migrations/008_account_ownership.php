<?php
/**
 * Which teacher created (and so administers) a student account.
 *
 * Teachers can enrol any existing student in their sections, but only the
 * teacher who created an account may change its name, email or ID, reset its
 * password or delete it. Without this, anyone could sign up as a teacher,
 * enrol a stranger's email and take over the account with a password reset.
 *
 * Keyed on user_id (never changes), so a teacher's email change needs no
 * cascade. Self-registered students have NULL: no teacher can change them.
 */
return function (mysqli $conn): void {
    ensure_column($conn, 'reg_info', 'managed_by', 'INT NULL');
    ensure_foreign_key($conn, 'reg_info', 'fk_reg_managed_by',
        'FOREIGN KEY (managed_by) REFERENCES reg_info(user_id) ON DELETE SET NULL');
};
