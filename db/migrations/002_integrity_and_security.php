<?php
/**
 * Data integrity and security columns.
 *
 * - reg_info gets a real primary key (user_id) and a required email.
 * - Every *_email reference gets a foreign key to reg_info(email) with
 *   ON UPDATE CASCADE, so an email change carries through automatically.
 *   History is protected: an account that still has quizzes or attempts
 *   cannot be deleted by accident (ON DELETE RESTRICT); section enrolments
 *   go with the account. Any orphaned email (an account deleted by hand in
 *   the past) gets a disabled placeholder account first, so no historical
 *   row is lost.
 * - One attempt number per student per quiz (stops a double "Start" from
 *   creating two attempts).
 * - questions.deleted_at: deleting a question that students have answered
 *   hides it instead of erasing their answers.
 * - Results release settings per quiz. Existing quizzes keep today's
 *   behaviour (results and answers shown right after submitting).
 * - Login/OTP throttling, hashed OTPs, and a per-account auth_version that
 *   ends other sessions after a password change or reset.
 */
return function (mysqli $conn): void {
    // ---- reg_info: primary key, required email ---------------------------------
    ensure_column($conn, 'reg_info', 'user_id', 'INT NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST');
    migration_exec($conn, "UPDATE reg_info SET email = CONCAT('missing-', user_id, '@invalid.local') WHERE email IS NULL OR email = ''");
    migration_exec($conn, "ALTER TABLE reg_info MODIFY email VARCHAR(100) NOT NULL");

    // ---- Placeholder accounts for orphaned references -----------------------------
    $orphans = [
        'student' => ["SELECT DISTINCT student_email AS e FROM quiz_attempts", "SELECT DISTINCT student_email AS e FROM section_students"],
        'teacher' => ["SELECT DISTINCT teacher_email AS e FROM quizzes", "SELECT DISTINCT teacher_email AS e FROM sections"],
    ];
    foreach ($orphans as $role => $queries) {
        foreach ($queries as $sql) {
            // An empty password hash can never verify, so the account cannot log in.
            migration_exec($conn,
                "INSERT INTO reg_info (full_name, email, pass, role)
                 SELECT 'Deleted account', o.e, '', '$role' FROM ($sql) o
                 WHERE NOT EXISTS (SELECT 1 FROM reg_info r WHERE r.email = o.e)"
            );
        }
    }

    // ---- Foreign keys to reg_info(email) --------------------------------------------
    ensure_foreign_key($conn, 'quiz_attempts', 'fk_attempts_student',
        'FOREIGN KEY (student_email) REFERENCES reg_info(email) ON UPDATE CASCADE ON DELETE RESTRICT');
    ensure_foreign_key($conn, 'section_students', 'fk_section_students_student',
        'FOREIGN KEY (student_email) REFERENCES reg_info(email) ON UPDATE CASCADE ON DELETE CASCADE');
    ensure_foreign_key($conn, 'quizzes', 'fk_quizzes_teacher',
        'FOREIGN KEY (teacher_email) REFERENCES reg_info(email) ON UPDATE CASCADE ON DELETE RESTRICT');
    ensure_foreign_key($conn, 'sections', 'fk_sections_teacher',
        'FOREIGN KEY (teacher_email) REFERENCES reg_info(email) ON UPDATE CASCADE ON DELETE RESTRICT');

    // ---- Attempts ---------------------------------------------------------------------
    ensure_index($conn, 'quiz_attempts', 'uq_attempt_number', 'UNIQUE KEY uq_attempt_number (quiz_id, student_email, attempt_number)');
    ensure_index($conn, 'quiz_attempts', 'idx_student_status', 'INDEX idx_student_status (student_email, status)');

    // ---- Questions: soft delete ---------------------------------------------------------
    ensure_column($conn, 'questions', 'deleted_at', 'DATETIME NULL');

    // ---- Results release ----------------------------------------------------------------
    // immediate: right after submitting; after_close: when the quiz closes or its
    // end time passes; manual: when the teacher releases them (results_released_at).
    ensure_column($conn, 'quizzes', 'results_release', "VARCHAR(20) NOT NULL DEFAULT 'immediate'");
    ensure_column($conn, 'quizzes', 'results_released_at', 'DATETIME NULL');
    ensure_column($conn, 'quizzes', 'show_answers', 'TINYINT(1) NOT NULL DEFAULT 1');

    // ---- Authentication ---------------------------------------------------------------------
    // OTPs are now stored hashed; any code issued before this migration is void.
    migration_exec($conn, "ALTER TABLE reg_info MODIFY otp VARCHAR(255) NULL");
    migration_exec($conn, "UPDATE reg_info SET otp = NULL, exp_time = NULL");
    ensure_column($conn, 'reg_info', 'otp_attempts', 'TINYINT NOT NULL DEFAULT 0');
    ensure_column($conn, 'reg_info', 'auth_version', 'INT NOT NULL DEFAULT 0');

    migration_exec($conn, "CREATE TABLE IF NOT EXISTS auth_throttle (
        id INT AUTO_INCREMENT PRIMARY KEY,
        action VARCHAR(20) NOT NULL,
        subject VARCHAR(190) NOT NULL,
        created_at DATETIME NOT NULL,
        INDEX idx_lookup (action, subject, created_at)
    )");
};
