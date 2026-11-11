<?php
/**
 * Practice mode and bookmarks.
 *
 * - quizzes.mode: 'official' (graded, counts) or 'practice' (practice only:
 *   unlimited attempts, never counts). quizzes.practice_enabled lets students
 *   practise an official quiz once its results and answers are released.
 * - Practice attempts (quiz_attempts.is_practice = 1) are numbered
 *   separately from official ones, so the unique key includes is_practice.
 * - question_bookmarks: a student's saved questions, pointing at the attempt
 *   snapshot so the question shows as it was, with a personal note.
 */
return function (mysqli $conn): void {
    ensure_column($conn, 'quizzes', 'mode', "VARCHAR(10) NOT NULL DEFAULT 'official'");
    ensure_column($conn, 'quizzes', 'practice_enabled', 'TINYINT(1) NOT NULL DEFAULT 0');

    if (index_exists($conn, 'quiz_attempts', 'uq_attempt_number')) {
        // The new key must exist before the old one goes (the quiz_id foreign key needs an index).
        ensure_index($conn, 'quiz_attempts', 'uq_attempt_number_mode', 'UNIQUE KEY uq_attempt_number_mode (quiz_id, student_email, is_practice, attempt_number)');
        migration_exec($conn, "ALTER TABLE quiz_attempts DROP INDEX uq_attempt_number");
    } else {
        ensure_index($conn, 'quiz_attempts', 'uq_attempt_number_mode', 'UNIQUE KEY uq_attempt_number_mode (quiz_id, student_email, is_practice, attempt_number)');
    }

    migration_exec($conn, "CREATE TABLE IF NOT EXISTS question_bookmarks (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_email VARCHAR(100) NOT NULL,
        attempt_id INT NOT NULL,
        question_id INT NOT NULL,
        note VARCHAR(500) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_bookmark (student_email, attempt_id, question_id),
        CONSTRAINT fk_bookmarks_student FOREIGN KEY (student_email) REFERENCES reg_info(email) ON UPDATE CASCADE ON DELETE CASCADE,
        CONSTRAINT fk_bookmarks_attempt FOREIGN KEY (attempt_id) REFERENCES quiz_attempts(id) ON DELETE CASCADE
    )");
};
