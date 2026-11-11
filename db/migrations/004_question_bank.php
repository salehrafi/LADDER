<?php
/**
 * Question bank, subjects and topics, new question types, grading rules and
 * the grade audit trail.
 *
 * - subjects / topics: each teacher's own catalogue.
 * - questions: a question with quiz_id NULL is a bank question owned by
 *   teacher_email. Questions added to a quiz from the bank are copies
 *   (source_question_id). type becomes VARCHAR so new types can be added:
 *   mcq, true_false, mcq_multi, short_answer, numeric, fill_blank, essay.
 *   answer_config (JSON) holds grading rules: accepted answers, case and
 *   punctuation handling, numeric tolerance, partial-credit scheme.
 * - attempt_questions snapshots those too (and the topic), so history keeps
 *   the exact rules each attempt was graded with.
 * - attempt_answers.answer_json: answers with several parts (multiple
 *   select, fill in the blanks).
 * - grade_audit: every score change by a teacher or a re-grade.
 */
return function (mysqli $conn): void {
    migration_exec($conn, "CREATE TABLE IF NOT EXISTS subjects (
        id INT AUTO_INCREMENT PRIMARY KEY,
        teacher_email VARCHAR(100) NOT NULL,
        name VARCHAR(80) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_teacher_subject (teacher_email, name),
        CONSTRAINT fk_subjects_teacher FOREIGN KEY (teacher_email) REFERENCES reg_info(email) ON UPDATE CASCADE ON DELETE RESTRICT
    )");
    migration_exec($conn, "CREATE TABLE IF NOT EXISTS topics (
        id INT AUTO_INCREMENT PRIMARY KEY,
        subject_id INT NOT NULL,
        name VARCHAR(80) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_subject_topic (subject_id, name),
        CONSTRAINT fk_topics_subject FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE
    )");

    // ---- questions ------------------------------------------------------------------
    migration_exec($conn, "ALTER TABLE questions MODIFY type VARCHAR(20) NOT NULL");
    migration_exec($conn, "ALTER TABLE questions MODIFY quiz_id INT NULL");
    ensure_column($conn, 'questions', 'teacher_email', 'VARCHAR(100) NULL AFTER quiz_id');
    migration_exec($conn, "UPDATE questions q JOIN quizzes z ON z.id = q.quiz_id SET q.teacher_email = z.teacher_email WHERE q.teacher_email IS NULL");
    ensure_column($conn, 'questions', 'topic_id', 'INT NULL');
    ensure_column($conn, 'questions', 'difficulty', 'VARCHAR(10) NULL');
    ensure_column($conn, 'questions', 'explanation', 'TEXT NULL');
    ensure_column($conn, 'questions', 'answer_config', 'TEXT NULL');
    ensure_column($conn, 'questions', 'source_question_id', 'INT NULL');
    ensure_column($conn, 'questions', 'updated_at', 'TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP');
    ensure_index($conn, 'questions', 'idx_bank', 'INDEX idx_bank (teacher_email, quiz_id, deleted_at)');
    ensure_foreign_key($conn, 'questions', 'fk_questions_topic', 'FOREIGN KEY (topic_id) REFERENCES topics(id) ON DELETE SET NULL');
    ensure_foreign_key($conn, 'questions', 'fk_questions_teacher', 'FOREIGN KEY (teacher_email) REFERENCES reg_info(email) ON UPDATE CASCADE ON DELETE RESTRICT');
    ensure_foreign_key($conn, 'questions', 'fk_questions_source', 'FOREIGN KEY (source_question_id) REFERENCES questions(id) ON DELETE SET NULL');

    // ---- quizzes: optional subject (for subject-wise analytics) -------------------------
    ensure_column($conn, 'quizzes', 'subject_id', 'INT NULL');
    ensure_foreign_key($conn, 'quizzes', 'fk_quizzes_subject', 'FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE SET NULL');

    // ---- snapshots and answers -----------------------------------------------------------
    ensure_column($conn, 'attempt_questions', 'answer_config', 'TEXT NULL');
    ensure_column($conn, 'attempt_questions', 'explanation', 'TEXT NULL');
    ensure_column($conn, 'attempt_questions', 'topic_id', 'INT NULL');
    ensure_column($conn, 'attempt_questions', 'topic_name', 'VARCHAR(80) NULL');
    ensure_column($conn, 'attempt_questions', 'subject_name', 'VARCHAR(80) NULL');
    ensure_column($conn, 'attempt_questions', 'difficulty', 'VARCHAR(10) NULL');
    ensure_column($conn, 'attempt_answers', 'answer_json', 'TEXT NULL');
    ensure_index($conn, 'attempt_answers', 'idx_attempt_question', 'INDEX idx_attempt_question (attempt_id, question_id)');

    migration_exec($conn, "CREATE TABLE IF NOT EXISTS grade_audit (
        id INT AUTO_INCREMENT PRIMARY KEY,
        attempt_id INT NOT NULL,
        attempt_answer_id INT NOT NULL,
        changed_by VARCHAR(100) NOT NULL,
        source VARCHAR(20) NOT NULL,
        old_points DECIMAL(5,2) NULL,
        new_points DECIMAL(5,2) NULL,
        note VARCHAR(255) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_attempt (attempt_id),
        CONSTRAINT fk_audit_attempt FOREIGN KEY (attempt_id) REFERENCES quiz_attempts(id) ON DELETE CASCADE,
        CONSTRAINT fk_audit_answer FOREIGN KEY (attempt_answer_id) REFERENCES attempt_answers(id) ON DELETE CASCADE
    )");
};
