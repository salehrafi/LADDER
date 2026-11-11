<?php
/**
 * Baseline: the schema as it was before versioned migrations (previously
 * db/schema.php + db/quiz_schema.php). Every statement is idempotent, so on
 * an existing database this only records the version.
 */
return function (mysqli $conn): void {
    migration_exec($conn, "CREATE TABLE IF NOT EXISTS reg_info(
        id VARCHAR(50) UNIQUE,
        full_name VARCHAR(50),
        email VARCHAR(100) UNIQUE,
        pass VARCHAR(255),
        role VARCHAR(20),
        otp INT,
        exp_time DATETIME
    )");

    migration_exec($conn, "CREATE TABLE IF NOT EXISTS quizzes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        teacher_email VARCHAR(100) NOT NULL,
        title VARCHAR(150) NOT NULL,
        description TEXT NULL,
        time_limit_minutes INT NULL,
        shuffle_questions TINYINT(1) NOT NULL DEFAULT 0,
        shuffle_options TINYINT(1) NOT NULL DEFAULT 0,
        max_attempts INT NOT NULL DEFAULT 1,
        start_time DATETIME NULL,
        end_time DATETIME NULL,
        status ENUM('draft','published','closed') NOT NULL DEFAULT 'draft',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_teacher (teacher_email),
        INDEX idx_status (status)
    )");

    migration_exec($conn, "CREATE TABLE IF NOT EXISTS questions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        quiz_id INT NOT NULL,
        type ENUM('mcq','short_answer') NOT NULL,
        question_text TEXT NOT NULL,
        points DECIMAL(5,2) NOT NULL DEFAULT 1.00,
        order_index INT NOT NULL DEFAULT 0,
        model_answer TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (quiz_id) REFERENCES quizzes(id) ON DELETE CASCADE,
        INDEX idx_quiz (quiz_id)
    )");

    migration_exec($conn, "CREATE TABLE IF NOT EXISTS question_options (
        id INT AUTO_INCREMENT PRIMARY KEY,
        question_id INT NOT NULL,
        option_text VARCHAR(500) NOT NULL,
        is_correct TINYINT(1) NOT NULL DEFAULT 0,
        order_index INT NOT NULL DEFAULT 0,
        FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE,
        INDEX idx_question (question_id)
    )");

    migration_exec($conn, "CREATE TABLE IF NOT EXISTS quiz_attempts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        quiz_id INT NOT NULL,
        student_email VARCHAR(100) NOT NULL,
        attempt_number INT NOT NULL,
        question_order TEXT NULL,
        option_order TEXT NULL,
        time_limit_minutes INT NULL,
        started_at DATETIME NOT NULL,
        submitted_at DATETIME NULL,
        total_score DECIMAL(6,2) NULL,
        max_score DECIMAL(6,2) NULL,
        needs_manual_grading TINYINT(1) NOT NULL DEFAULT 0,
        status ENUM('in_progress','submitted') NOT NULL DEFAULT 'in_progress',
        FOREIGN KEY (quiz_id) REFERENCES quizzes(id) ON DELETE CASCADE,
        INDEX idx_quiz_student (quiz_id, student_email),
        INDEX idx_status (status)
    )");

    migration_exec($conn, "CREATE TABLE IF NOT EXISTS attempt_answers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        attempt_id INT NOT NULL,
        question_id INT NOT NULL,
        selected_option_id INT NULL,
        answer_text TEXT NULL,
        is_correct TINYINT(1) NULL,
        points_awarded DECIMAL(5,2) NULL,
        graded_by VARCHAR(100) NULL,
        graded_at DATETIME NULL,
        FOREIGN KEY (attempt_id) REFERENCES quiz_attempts(id) ON DELETE CASCADE,
        FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE,
        INDEX idx_attempt (attempt_id)
    )");

    // Columns added after the first release.
    ensure_column($conn, 'quiz_attempts', 'draft_answers', 'TEXT NULL');            // autosave
    ensure_column($conn, 'attempt_answers', 'feedback', 'TEXT NULL');               // teacher feedback
    ensure_column($conn, 'quizzes', 'lock_on_leave', 'TINYINT(1) NOT NULL DEFAULT 1'); // exam mode
    ensure_column($conn, 'quiz_attempts', 'submit_reason', 'VARCHAR(20) NULL');     // manual, time_up, left_page, exit_fullscreen
    ensure_column($conn, 'quiz_attempts', 'entered_at', 'DATETIME NULL');           // exam-mode start gate
    ensure_column($conn, 'reg_info', 'must_change_password', 'TINYINT(1) NOT NULL DEFAULT 0'); // teacher-set default password

    // Classes: each teacher manages their own sections and students.
    migration_exec($conn, "CREATE TABLE IF NOT EXISTS sections (
        id INT AUTO_INCREMENT PRIMARY KEY,
        teacher_email VARCHAR(100) NOT NULL,
        name VARCHAR(80) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_teacher_section (teacher_email, name)
    )");
    // A student can belong to sections of several teachers.
    migration_exec($conn, "CREATE TABLE IF NOT EXISTS section_students (
        section_id INT NOT NULL,
        student_email VARCHAR(100) NOT NULL,
        added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (section_id, student_email),
        INDEX idx_student (student_email),
        FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE CASCADE
    )");
    // Which sections may take a quiz. A quiz with no rows here is open to every student.
    migration_exec($conn, "CREATE TABLE IF NOT EXISTS quiz_sections (
        quiz_id INT NOT NULL,
        section_id INT NOT NULL,
        PRIMARY KEY (quiz_id, section_id),
        FOREIGN KEY (quiz_id) REFERENCES quizzes(id) ON DELETE CASCADE,
        FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE CASCADE
    )");
};
