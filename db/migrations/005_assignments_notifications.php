<?php
/**
 * Quiz assignments and notifications.
 *
 * - quiz_assignments replaces quiz_sections: a quiz can be assigned to all of
 *   the teacher's students, to sections, or to individual students, each
 *   with its own availability window (open, due, late submissions until).
 *   Empty columns fall back to the quiz's own start/end times. A quiz with
 *   no assignments stays open to all of the teacher's students, as before.
 *   Existing quiz_sections rows are copied over, then the old table is
 *   dropped (one source of truth).
 * - quiz_attempts.due_at: the deadline that applied when the attempt
 *   started, so "submitted late" is fixed history, not recalculated.
 * - notifications: per-user inbox; dedupe_key stops repeats of the same event.
 * - reg_info.email_notifications: opt-in for notification emails.
 */
return function (mysqli $conn): void {
    migration_exec($conn, "CREATE TABLE IF NOT EXISTS quiz_assignments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        quiz_id INT NOT NULL,
        target_type VARCHAR(10) NOT NULL,
        section_id INT NULL,
        student_email VARCHAR(100) NULL,
        available_from DATETIME NULL,
        due_at DATETIME NULL,
        late_until DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_assignment (quiz_id, target_type, section_id, student_email),
        INDEX idx_student (student_email),
        INDEX idx_section (section_id),
        CONSTRAINT fk_assign_quiz FOREIGN KEY (quiz_id) REFERENCES quizzes(id) ON DELETE CASCADE,
        CONSTRAINT fk_assign_section FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE CASCADE,
        CONSTRAINT fk_assign_student FOREIGN KEY (student_email) REFERENCES reg_info(email) ON UPDATE CASCADE ON DELETE CASCADE
    )");

    $has_old = $conn->query("SELECT COUNT(*) AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'quiz_sections'")->fetch_assoc();
    if ((int) $has_old['c'] === 1) {
        migration_exec($conn,
            "INSERT IGNORE INTO quiz_assignments (quiz_id, target_type, section_id)
             SELECT quiz_id, 'section', section_id FROM quiz_sections"
        );
        migration_exec($conn, "DROP TABLE quiz_sections");
    }

    ensure_column($conn, 'quiz_attempts', 'due_at', 'DATETIME NULL');
    // Practice attempts (Phase 5) never count towards grades, completion or
    // analytics; added here so every query can already exclude them.
    ensure_column($conn, 'quiz_attempts', 'is_practice', 'TINYINT(1) NOT NULL DEFAULT 0');

    migration_exec($conn, "CREATE TABLE IF NOT EXISTS notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_email VARCHAR(100) NOT NULL,
        type VARCHAR(30) NOT NULL,
        title VARCHAR(200) NOT NULL,
        body VARCHAR(500) NULL,
        link VARCHAR(255) NULL,
        dedupe_key VARCHAR(150) NULL,
        read_at DATETIME NULL,
        emailed_at DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_dedupe (user_email, dedupe_key),
        INDEX idx_user_unread (user_email, read_at),
        CONSTRAINT fk_notifications_user FOREIGN KEY (user_email) REFERENCES reg_info(email) ON UPDATE CASCADE ON DELETE CASCADE
    )");

    ensure_column($conn, 'reg_info', 'email_notifications', 'TINYINT(1) NOT NULL DEFAULT 0');
};
