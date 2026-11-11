<?php
/**
 * attempt_questions: the questions, options (in the order the student saw
 * them) and answer keys an attempt was taken with. Grading and reviews read
 * this copy, so later edits to a question never change past results.
 *
 * Existing attempts are backfilled from the questions as they are now. For
 * submitted attempts these rows are flagged backfilled = 1: they reflect the
 * question at migration time, which may differ from what the student saw.
 *
 * Self-contained on purpose: it only uses columns that exist at this point
 * of the migration history, so later changes to the app's helpers cannot
 * break an upgrade from an older database.
 */
return function (mysqli $conn): void {
    migration_exec($conn, "CREATE TABLE IF NOT EXISTS attempt_questions (
        attempt_id INT NOT NULL,
        position INT NOT NULL,
        question_id INT NULL,
        type VARCHAR(20) NOT NULL,
        question_text TEXT NOT NULL,
        points DECIMAL(5,2) NOT NULL,
        model_answer TEXT NULL,
        options_json TEXT NULL,
        backfilled TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (attempt_id, position),
        UNIQUE KEY uq_attempt_question (attempt_id, question_id),
        INDEX idx_question (question_id),
        CONSTRAINT fk_attempt_questions_attempt FOREIGN KEY (attempt_id) REFERENCES quiz_attempts(id) ON DELETE CASCADE,
        CONSTRAINT fk_attempt_questions_question FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE SET NULL
    )");

    $attempts = $conn->query(
        "SELECT qa.id, qa.status, qa.question_order, qa.option_order FROM quiz_attempts qa
         WHERE NOT EXISTS (SELECT 1 FROM attempt_questions aq WHERE aq.attempt_id = qa.id)"
    )->fetch_all(MYSQLI_ASSOC);
    if (!$attempts) {
        return;
    }

    $q_stmt = $conn->prepare("SELECT id, type, question_text, points, model_answer FROM questions WHERE id = ?");
    $o_stmt = $conn->prepare("SELECT id, option_text, is_correct FROM question_options WHERE question_id = ? ORDER BY order_index, id");
    $ins = $conn->prepare(
        "INSERT IGNORE INTO attempt_questions (attempt_id, position, question_id, type, question_text, points, model_answer, options_json, backfilled)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    foreach ($attempts as $attempt) {
        $attempt_id = (int) $attempt['id'];
        $backfilled = $attempt['status'] === 'submitted' ? 1 : 0;
        $order = json_decode($attempt['question_order'] ?? '[]', true) ?: [];
        $option_order = json_decode($attempt['option_order'] ?? '{}', true) ?: [];
        $position = 0;
        foreach (array_unique(array_map('intval', $order)) as $qid) {
            $q_stmt->bind_param("i", $qid);
            $q_stmt->execute();
            $q = $q_stmt->get_result()->fetch_assoc();
            if (!$q) {
                continue;
            }
            $o_stmt->bind_param("i", $qid);
            $o_stmt->execute();
            $by_id = [];
            foreach ($o_stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $o) {
                $by_id[(int) $o['id']] = ['id' => (int) $o['id'], 'text' => $o['option_text'], 'correct' => (int) $o['is_correct'] === 1];
            }
            $ordered = [];
            foreach ((array) ($option_order[$qid] ?? $option_order[(string) $qid] ?? []) as $oid) {
                if (isset($by_id[(int) $oid])) {
                    $ordered[(int) $oid] = $by_id[(int) $oid];
                }
            }
            $ordered += $by_id;
            $options = json_encode(array_values($ordered), JSON_UNESCAPED_UNICODE);
            $points = (float) $q['points'];
            $ins->bind_param("iiissdssi", $attempt_id, $position, $qid, $q['type'], $q['question_text'], $points, $q['model_answer'], $options, $backfilled);
            $ins->execute();
            $position++;
        }
    }
    $q_stmt->close();
    $o_stmt->close();
    $ins->close();
};
