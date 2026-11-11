<?php
/**
 * Shared quiz-attempt logic: starting attempts, question snapshots,
 * deadlines, draft (autosave) answers, validation, grading on submit, and
 * when students may see their results.
 *
 * Snapshots: when an attempt starts, the questions, options and answer keys
 * it uses are copied into attempt_questions. Taking, grading and reviewing
 * the attempt read that copy, so editing or deleting a question later never
 * changes a result that was already given.
 */

require_once __DIR__ . '/grading.php';

// A submit that arrives this many seconds after the deadline is still accepted
// (covers network delay). Anything later is graded from the last autosave.
const QUIZ_SUBMIT_GRACE_SECONDS = 15;

// Max length of a short answer, so one student cannot store huge blobs.
const SHORT_ANSWER_MAX_LENGTH = 5000;

// How an attempt was closed => label shown to teachers and students.
const SUBMIT_REASONS = [
    'manual' => 'Submitted by student',
    'time_up' => 'Auto-submitted: time ran out',
    'left_page' => 'Auto-submitted: student left the quiz page',
    'exit_fullscreen' => 'Auto-submitted: student left full screen',
];

// Reasons the browser may report when it auto-submits a locked quiz.
const LEAVE_REASONS = ['left_page', 'exit_fullscreen'];

// When students see scores, correct answers and feedback.
const RESULTS_RELEASE = [
    'immediate' => 'Right after they submit',
    'after_close' => 'When the quiz closes (status Closed or end time passed)',
    'manual' => 'Only when I release them',
];

// ---- Results release ----------------------------------------------------------------

/** May students see scores and feedback for this quiz yet? */
function results_released(array $quiz): bool
{
    if (!empty($quiz['results_released_at'])) {
        return true;
    }
    switch ($quiz['results_release'] ?? 'immediate') {
        case 'manual':
            return false;
        case 'after_close':
            return ($quiz['status'] ?? '') === 'closed'
                || (!empty($quiz['end_time']) && time() > strtotime($quiz['end_time']));
        default:
            return true;
    }
}

/** SQL form of results_released() for quiz alias $q (keep the two in step). */
function results_released_sql(string $q = 'q'): string
{
    return "($q.results_released_at IS NOT NULL
             OR $q.results_release NOT IN ('manual', 'after_close')
             OR ($q.results_release = 'after_close'
                 AND ($q.status = 'closed' OR ($q.end_time IS NOT NULL AND $q.end_time < NOW()))))";
}

/** Short explanation shown to a student while results are held back. */
function results_pending_note(array $quiz): string
{
    if (($quiz['results_release'] ?? '') === 'after_close') {
        return !empty($quiz['end_time'])
            ? 'Results will be available after ' . date('M j, Y g:i A', strtotime($quiz['end_time'])) . '.'
            : 'Results will be available when your teacher closes the quiz.';
    }
    return 'Your teacher will release the results.';
}

/** May students see which answers were correct (only once results are released)? */
function answers_visible(array $quiz): bool
{
    return results_released($quiz) && (int) ($quiz['show_answers'] ?? 1) === 1;
}

// ---- Snapshots ----------------------------------------------------------------------

/**
 * Copy questions from the live tables, in the given order, with options in
 * the given per-question order. Questions that no longer exist are skipped.
 *
 * @param int[] $question_ids
 * @param array<int|string,int[]> $option_order question id => option ids
 * @return array<int,array> snapshot rows in order
 */
function build_snapshot_rows(mysqli $conn, array $question_ids, array $option_order = []): array
{
    $question_ids = array_values(array_unique(array_map('intval', $question_ids)));
    if (!$question_ids) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($question_ids), '?'));
    $types = str_repeat('i', count($question_ids));

    $stmt = $conn->prepare(
        "SELECT q.id, q.type, q.question_text, q.points, q.model_answer, q.answer_config, q.explanation,
                q.difficulty, q.topic_id, t.name AS topic_name, s.name AS subject_name
         FROM questions q
         LEFT JOIN topics t ON t.id = q.topic_id
         LEFT JOIN subjects s ON s.id = t.subject_id
         WHERE q.id IN ($placeholders)"
    );
    $stmt->bind_param($types, ...$question_ids);
    $stmt->execute();
    $questions = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $q) {
        $questions[(int) $q['id']] = $q;
    }
    $stmt->close();

    $stmt = $conn->prepare("SELECT id, question_id, option_text, is_correct FROM question_options WHERE question_id IN ($placeholders) ORDER BY order_index ASC, id ASC");
    $stmt->bind_param($types, ...$question_ids);
    $stmt->execute();
    $options = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $o) {
        $options[(int) $o['question_id']][(int) $o['id']] = $o;
    }
    $stmt->close();

    $rows = [];
    foreach ($question_ids as $qid) {
        if (!isset($questions[$qid])) {
            continue;
        }
        $q = $questions[$qid];
        $by_id = $options[$qid] ?? [];
        // The attempt's display order first, then any option it did not list.
        $ordered = [];
        foreach ($option_order[$qid] ?? $option_order[(string) $qid] ?? [] as $oid) {
            if (isset($by_id[(int) $oid])) {
                $ordered[(int) $oid] = $by_id[(int) $oid];
            }
        }
        $ordered += $by_id;

        $rows[] = [
            'question_id' => $qid,
            'type' => $q['type'],
            'question_text' => $q['question_text'],
            'points' => (float) $q['points'],
            'model_answer' => $q['model_answer'],
            'answer_config' => $q['answer_config'],
            'explanation' => $q['explanation'],
            'difficulty' => $q['difficulty'],
            'topic_id' => $q['topic_id'] !== null ? (int) $q['topic_id'] : null,
            'topic_name' => $q['topic_name'],
            'subject_name' => $q['subject_name'],
            'options' => array_values(array_map(fn($o) => [
                'id' => (int) $o['id'],
                'text' => $o['option_text'],
                'correct' => (int) $o['is_correct'] === 1,
            ], $ordered)),
        ];
    }
    return $rows;
}

/** Store snapshot rows for an attempt (ignored if another request stored them first). */
function save_attempt_snapshot(mysqli $conn, int $attempt_id, array $rows, bool $backfilled = false): void
{
    $stmt = $conn->prepare(
        "INSERT IGNORE INTO attempt_questions
            (attempt_id, position, question_id, type, question_text, points, model_answer, options_json, backfilled,
             answer_config, explanation, difficulty, topic_id, topic_name, subject_name)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $flag = $backfilled ? 1 : 0;
    foreach (array_values($rows) as $position => $r) {
        $options = json_encode($r['options'], JSON_UNESCAPED_UNICODE);
        $qid = (int) $r['question_id'];
        $points = (float) $r['points'];
        $config = $r['answer_config'] ?? null;
        $explanation = $r['explanation'] ?? null;
        $difficulty = $r['difficulty'] ?? null;
        $topic_id = $r['topic_id'] ?? null;
        $topic_name = $r['topic_name'] ?? null;
        $subject_name = $r['subject_name'] ?? null;
        $stmt->bind_param("iiissdssisssiss", $attempt_id, $position, $qid, $r['type'], $r['question_text'], $points,
            $r['model_answer'], $options, $flag, $config, $explanation, $difficulty, $topic_id, $topic_name, $subject_name);
        $stmt->execute();
    }
    $stmt->close();
}

/**
 * Make sure an attempt has a snapshot. Attempts created before snapshots
 * existed (or inserted directly, e.g. by the demo seeder) get one built from
 * the current questions; for already submitted attempts it is flagged as
 * backfilled, since the questions may have changed since.
 */
function ensure_attempt_snapshot(mysqli $conn, array $attempt): void
{
    $id = (int) $attempt['id'];
    $stmt = $conn->prepare("SELECT 1 FROM attempt_questions WHERE attempt_id = ? LIMIT 1");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $has = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    if ($has) {
        return;
    }
    $order = json_decode($attempt['question_order'] ?? '[]', true) ?: [];
    $option_order = json_decode($attempt['option_order'] ?? '{}', true) ?: [];
    $rows = build_snapshot_rows($conn, $order, is_array($option_order) ? $option_order : []);
    save_attempt_snapshot($conn, $id, $rows, ($attempt['status'] ?? '') === 'submitted');
}

/**
 * The attempt's questions as they were when it started.
 *
 * @return array<int,array> question id => [question_id, position, type, question_text,
 *         points, model_answer, options => [[id, text, correct]], backfilled], in order
 */
function load_attempt_snapshot(mysqli $conn, array $attempt): array
{
    ensure_attempt_snapshot($conn, $attempt);
    $id = (int) $attempt['id'];
    $stmt = $conn->prepare("SELECT * FROM attempt_questions WHERE attempt_id = ? ORDER BY position ASC");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $out = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        if ($r['question_id'] === null) {
            continue;
        }
        $r['question_id'] = (int) $r['question_id'];
        $r['position'] = (int) $r['position'];
        $r['points'] = (float) $r['points'];
        $r['backfilled'] = (int) $r['backfilled'] === 1;
        $r['options'] = json_decode($r['options_json'] ?? '[]', true) ?: [];
        $r['config'] = question_config($r);
        unset($r['options_json']);
        $out[$r['question_id']] = $r;
    }
    $stmt->close();
    return $out;
}

/**
 * One row per question of a submitted attempt, in the order the student saw
 * them: the snapshot merged with the graded answer. Keys match the old
 * questions JOIN (question_text, type, question_points, model_answer), plus
 * options => [[id, option_text, is_correct]].
 */
function attempt_review_rows(mysqli $conn, array $attempt): array
{
    $snapshot = load_attempt_snapshot($conn, $attempt);
    $id = (int) $attempt['id'];
    $stmt = $conn->prepare("SELECT * FROM attempt_answers WHERE attempt_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $answers = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $a) {
        $answers[(int) $a['question_id']] = $a;
    }
    $stmt->close();

    $rows = [];
    foreach ($snapshot as $qid => $q) {
        if (!isset($answers[$qid])) {
            continue;
        }
        $rows[] = $answers[$qid] + [
            'question_text' => $q['question_text'],
            'type' => $q['type'],
            'question_points' => $q['points'],
            'model_answer' => $q['model_answer'],
            'backfilled' => $q['backfilled'],
            'config' => $q['config'],
            'explanation' => $q['explanation'],
            'topic_name' => $q['topic_name'],
            'parts' => json_decode((string) ($answers[$qid]['answer_json'] ?? ''), true) ?: [],
            'options' => array_map(fn($o) => [
                'id' => (int) $o['id'],
                'option_text' => $o['text'],
                'is_correct' => !empty($o['correct']) ? 1 : 0,
            ], $q['options']),
        ];
    }
    return $rows;
}

// ---- Starting an attempt ----------------------------------------------------------------

/**
 * Resume the student's in-progress attempt or start a new one, with its
 * question order, option order and snapshot. Runs in one transaction with the
 * quiz row locked, so two simultaneous "Start" clicks cannot create two
 * attempts or go past the attempt limit. Availability and visibility are the
 * caller's job.
 *
 * @return array{attempt:?array, error:?string} error is 'no_attempts_left' or 'no_questions'
 */
function start_or_resume_attempt(mysqli $conn, array $quiz, string $student_email, ?string $due_at = null, bool $practice = false, bool $timed = false): array
{
    // Practice attempts: separate numbering, unlimited, timed only on request.
    $p = $practice ? 1 : 0;
    $quiz_id = (int) $quiz['id'];
    $conn->begin_transaction();
    try {
        $lock = $conn->prepare("SELECT id FROM quizzes WHERE id = ? FOR UPDATE");
        $lock->bind_param("i", $quiz_id);
        $lock->execute();
        $lock->get_result();
        $lock->close();

        $stmt = $conn->prepare("SELECT * FROM quiz_attempts WHERE quiz_id = ? AND student_email = ? AND status = 'in_progress' AND is_practice = ? ORDER BY id LIMIT 1");
        $stmt->bind_param("isi", $quiz_id, $student_email, $p);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($existing) {
            $conn->commit();
            return ['attempt' => $existing, 'error' => null];
        }

        $stmt = $conn->prepare(
            "SELECT SUM(status = 'submitted') AS used, COALESCE(MAX(attempt_number), 0) AS last
             FROM quiz_attempts WHERE quiz_id = ? AND student_email = ? AND is_practice = ?"
        );
        $stmt->bind_param("isi", $quiz_id, $student_email, $p);
        $stmt->execute();
        $counts = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $used = (int) ($counts['used'] ?? 0);
        $max = (int) $quiz['max_attempts'];
        if (!$practice && $max !== 0 && $used >= $max) {
            $conn->rollback();
            return ['attempt' => null, 'error' => 'no_attempts_left'];
        }

        $stmt = $conn->prepare("SELECT id FROM questions WHERE quiz_id = ? AND deleted_at IS NULL ORDER BY order_index ASC, id ASC");
        $stmt->bind_param("i", $quiz_id);
        $stmt->execute();
        $question_ids = array_map('intval', array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'id'));
        $stmt->close();
        if (!$question_ids) {
            $conn->rollback();
            return ['attempt' => null, 'error' => 'no_questions'];
        }
        if (!empty($quiz['shuffle_questions'])) {
            shuffle($question_ids);
        }

        $rows = build_snapshot_rows($conn, $question_ids);
        $option_order = [];
        foreach ($rows as &$row) {
            if ($row['options']) {
                if (!empty($quiz['shuffle_options'])) {
                    shuffle($row['options']);
                }
                $option_order[$row['question_id']] = array_column($row['options'], 'id');
            }
        }
        unset($row);

        $attempt_number = (int) ($counts['last'] ?? 0) + 1;
        $qorder_json = json_encode($question_ids);
        $oorder_json = json_encode((object) $option_order);
        $limit = $quiz['time_limit_minutes'] !== null && (!$practice || $timed) ? (int) $quiz['time_limit_minutes'] : null;
        $due_at = $practice ? null : $due_at;
        $ins = $conn->prepare(
            "INSERT INTO quiz_attempts (quiz_id, student_email, attempt_number, question_order, option_order, time_limit_minutes, started_at, status, due_at, is_practice)
             VALUES (?, ?, ?, ?, ?, ?, NOW(), 'in_progress', ?, ?)"
        );
        // due_at: the deadline in force now, so lateness is judged against it later.
        $ins->bind_param("isissisi", $quiz_id, $student_email, $attempt_number, $qorder_json, $oorder_json, $limit, $due_at, $p);
        $ins->execute();
        $attempt_id = (int) $ins->insert_id;
        $ins->close();

        save_attempt_snapshot($conn, $attempt_id, $rows);
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }

    $stmt = $conn->prepare("SELECT * FROM quiz_attempts WHERE id = ?");
    $stmt->bind_param("i", $attempt_id);
    $stmt->execute();
    $attempt = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return ['attempt' => $attempt, 'error' => null];
}

/**
 * Exam mode: the student passed the full-screen start gate. The countdown
 * starts now (not when the attempt row was created), and only the first call
 * counts, so the timer can never be reset.
 *
 * @return array the attempt row after the update
 */
function begin_attempt(mysqli $conn, array $attempt): array
{
    $id = (int) $attempt['id'];
    $stmt = $conn->prepare(
        "UPDATE quiz_attempts SET started_at = NOW(), entered_at = NOW()
         WHERE id = ? AND status = 'in_progress' AND entered_at IS NULL"
    );
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("SELECT * FROM quiz_attempts WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: $attempt;
}

function submit_reason_label(?string $reason): string
{
    return SUBMIT_REASONS[$reason ?? ''] ?? '';
}

/**
 * Close an in-progress attempt because the student left a locked quiz page
 * (switched tab/app, minimised, closed). Answers sent with the beacon are
 * used while the attempt is within its time limit, otherwise the last
 * autosave is used, same as a late manual submit.
 *
 * @return bool true if the attempt was closed by this call
 */
function submit_on_leave(mysqli $conn, array $attempt, array $raw_answers, string $reason = 'left_page'): bool
{
    if (!in_array($reason, LEAVE_REASONS, true)) {
        $reason = 'left_page';
    }
    $deadline = attempt_deadline_epoch($attempt);
    $late = $deadline !== null && time() > $deadline + QUIZ_SUBMIT_GRACE_SECONDS;

    $answers = $late ? load_draft($attempt) : sanitize_answers($conn, $attempt, $raw_answers);
    if (!$late && empty($answers)) {
        // Nothing (valid) in the beacon: fall back to the autosaved draft.
        $answers = load_draft($attempt);
    }
    return finalize_attempt($conn, $attempt, $answers, $late ? 'time_up' : $reason);
}

function attempt_deadline_epoch(array $attempt): ?int
{
    if (empty($attempt['time_limit_minutes'])) {
        return null;
    }
    return strtotime($attempt['started_at']) + ((int) $attempt['time_limit_minutes'] * 60);
}

/** Fetch an attempt only if it belongs to this student and has the given status. */
function load_attempt_for_student(mysqli $conn, int $attempt_id, string $student_email, string $status = 'in_progress'): ?array
{
    $stmt = $conn->prepare(
        "SELECT * FROM quiz_attempts WHERE id = ? AND student_email = ? AND status = ? LIMIT 1"
    );
    $stmt->bind_param("iss", $attempt_id, $student_email, $status);
    $stmt->execute();
    $attempt = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $attempt ?: null;
}

/**
 * Keep only answers that belong to this attempt and are well formed. Client
 * input is never trusted: unknown questions are dropped, MCQ choices must be
 * options of that question (as snapshotted), and short answers are length-limited.
 *
 * @return array<string,string> e.g. ['q_12' => '34', 'q_15' => 'some text']
 */
function sanitize_answers(mysqli $conn, array $attempt, array $raw): array
{
    $clean = [];
    foreach (load_attempt_snapshot($conn, $attempt) as $qid => $q) {
        $field = "q_$qid";
        if (!array_key_exists($field, $raw)) {
            continue;
        }
        $value = sanitize_answer_value($q, $raw[$field]);
        if ($value !== null) {
            $clean[$field] = $value;
        }
    }
    return $clean;
}

/** Store the student's current answers so they survive reloads and crashes. */
function save_draft(mysqli $conn, int $attempt_id, array $answers): void
{
    $json = json_encode($answers, JSON_UNESCAPED_UNICODE);
    $stmt = $conn->prepare("UPDATE quiz_attempts SET draft_answers = ? WHERE id = ? AND status = 'in_progress'");
    $stmt->bind_param("si", $json, $attempt_id);
    $stmt->execute();
    $stmt->close();
}

/** Read the saved draft for an attempt as a field => value map. */
function load_draft(array $attempt): array
{
    $draft = json_decode($attempt['draft_answers'] ?? '', true);
    return is_array($draft) ? $draft : [];
}

/**
 * Grade and close an attempt. MCQ is graded against the correct option;
 * short answers are auto-graded only when a model answer exists, otherwise
 * they wait for the teacher (needs_manual_grading = 1). Grading uses the
 * attempt's snapshot, not the live questions.
 *
 * Runs in one transaction and first "claims" the attempt (in_progress ->
 * submitted), so a double submit, an autosubmit racing a manual submit, or a
 * page reload can never grade the same attempt twice.
 *
 * @param array<string,string> $answers sanitized answers (see sanitize_answers)
 * @param string $reason one of SUBMIT_REASONS, stored for teacher review
 * @return bool true if this call graded the attempt, false if it was already closed
 */
function finalize_attempt(mysqli $conn, array $attempt, array $answers, string $reason = 'manual'): bool
{
    $attempt_id = (int) $attempt['id'];
    if (!isset(SUBMIT_REASONS[$reason])) {
        $reason = 'manual';
    }
    // Before the transaction: a snapshot built here must not be rolled back
    // by a lost race, it is valid either way.
    $snapshot = load_attempt_snapshot($conn, $attempt);

    $conn->begin_transaction();
    try {
        $claim = $conn->prepare(
            "UPDATE quiz_attempts SET status = 'submitted', submitted_at = NOW(), submit_reason = ?
             WHERE id = ? AND status = 'in_progress'"
        );
        $claim->bind_param("si", $reason, $attempt_id);
        $claim->execute();
        $claimed = $claim->affected_rows === 1;
        $claim->close();

        if (!$claimed) {
            $conn->rollback();
            return false;
        }

        grade_attempt_answers($conn, $attempt_id, $snapshot, $answers);
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
    // Outside the transaction: tell the teacher if something needs hand grading.
    if (function_exists('notify_pending_evaluation')) {
        notify_pending_evaluation($conn, $attempt_id);
    }
    return true;
}

/** Normalise a short answer for exact-match comparison (case and spacing). */
function normalize_short_answer(?string $s): string
{
    return preg_replace('/\s+/u', ' ', mb_strtolower(trim((string) $s), 'UTF-8'));
}

/**
 * Insert graded answer rows and store totals. Caller owns the transaction.
 *
 * @param array<int,array> $snapshot see load_attempt_snapshot()
 */
function grade_attempt_answers(mysqli $conn, int $attempt_id, array $snapshot, array $answers): void
{
    $ins = $conn->prepare(
        "INSERT INTO attempt_answers (attempt_id, question_id, selected_option_id, answer_text, is_correct, points_awarded, graded_at, answer_json)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    );

    $total_score = 0.0;
    $max_score = 0.0;
    $needs_manual = 0;

    foreach ($snapshot as $qid => $question) {
        $qid = (int) $qid;
        $max_score += (float) $question['points'];

        $result = grade_question($question, $answers["q_$qid"] ?? null);
        $graded_at = $result['points'] === null ? null : date('Y-m-d H:i:s');
        if ($result['points'] === null) {
            $needs_manual = 1;
        } else {
            $total_score += $result['points'];
        }

        $ins->bind_param("iiisidss", $attempt_id, $qid, $result['selected_option_id'], $result['answer_text'],
            $result['is_correct'], $result['points'], $graded_at, $result['answer_json']);
        $ins->execute();
    }
    $ins->close();

    $upd = $conn->prepare(
        "UPDATE quiz_attempts
         SET total_score = ?, max_score = ?, needs_manual_grading = ?, draft_answers = NULL
         WHERE id = ?"
    );
    $upd->bind_param("ddii", $total_score, $max_score, $needs_manual, $attempt_id);
    $upd->execute();
    $upd->close();
}

/**
 * Save a teacher's scores and feedback for one attempt and recompute its
 * total, in one transaction. Scores are clamped to 0..the question's points
 * as they were when the attempt was taken (the snapshot).
 *
 * @param array<int|string,string> $scores answer id => score text ('' = ungraded)
 * @param array<int|string,string> $feedback answer id => note
 * @return bool true if something still needs a score
 */
function save_teacher_grades(mysqli $conn, array $attempt, string $teacher_email, array $scores, array $feedback): bool
{
    $attempt_id = (int) $attempt['id'];
    $max_by_answer = [];
    $old_points = [];
    $old_feedback = [];
    foreach (attempt_review_rows($conn, $attempt) as $row) {
        $max_by_answer[(int) $row['id']] = (float) $row['question_points'];
        $old_points[(int) $row['id']] = $row['points_awarded'] === null ? null : (float) $row['points_awarded'];
        $old_feedback[(int) $row['id']] = (string) ($row['feedback'] ?? '');
    }
    $feedback_added = false;

    $conn->begin_transaction();
    try {
        $upd = $conn->prepare(
            "UPDATE attempt_answers SET points_awarded = ?, is_correct = ?, feedback = ?, graded_by = ?, graded_at = NOW()
             WHERE id = ? AND attempt_id = ?"
        );
        foreach ($max_by_answer as $answer_id => $max_points) {
            if (!array_key_exists($answer_id, $scores) && !array_key_exists($answer_id, $feedback)) {
                continue;
            }
            $raw = trim((string) ($scores[$answer_id] ?? ''));
            $points = null;
            if ($raw !== '' && is_numeric($raw)) {
                $points = round(min(max(0.0, (float) $raw), $max_points), 2);
            }
            // Full marks count as correct; anything less is not.
            $is_correct = $points === null ? null : ($points >= $max_points ? 1 : 0);
            $note = trim((string) ($feedback[$answer_id] ?? ''));
            $note = $note === '' ? null : mb_substr($note, 0, 2000);

            $upd->bind_param("dissii", $points, $is_correct, $note, $teacher_email, $answer_id, $attempt_id);
            $upd->execute();
            if ($note !== null && $note !== $old_feedback[$answer_id]) {
                $feedback_added = true;
            }
            if ($points !== $old_points[$answer_id]) {
                record_grade_change($conn, $attempt_id, $answer_id, $teacher_email, 'manual', $old_points[$answer_id], $points);
            }
        }
        $upd->close();

        $sum = $conn->prepare(
            "SELECT SUM(points_awarded) AS total, SUM(points_awarded IS NULL) AS pending
             FROM attempt_answers WHERE attempt_id = ?"
        );
        $sum->bind_param("i", $attempt_id);
        $sum->execute();
        $sums = $sum->get_result()->fetch_assoc();
        $sum->close();

        $needs_manual = ((int) $sums['pending'] > 0) ? 1 : 0;
        $total = (float) ($sums['total'] ?? 0);
        $u = $conn->prepare("UPDATE quiz_attempts SET total_score = ?, needs_manual_grading = ? WHERE id = ?");
        $u->bind_param("dii", $total, $needs_manual, $attempt_id);
        $u->execute();
        $u->close();

        $conn->commit();
        if ($feedback_added && function_exists('notify_feedback')) {
            notify_feedback($conn, $attempt);
        }
        return $needs_manual === 1;
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

/** One row in the grade audit trail (who changed which score, from what to what). */
function record_grade_change(mysqli $conn, int $attempt_id, int $answer_id, string $by, string $source, ?float $old, ?float $new, ?string $note = null): void
{
    $stmt = $conn->prepare(
        "INSERT INTO grade_audit (attempt_id, attempt_answer_id, changed_by, source, old_points, new_points, note)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param("iissdds", $attempt_id, $answer_id, $by, $source, $old, $new, $note);
    $stmt->execute();
    $stmt->close();
}

/** @return array<int,array> audit rows of an attempt, newest first */
function attempt_grade_history(mysqli $conn, int $attempt_id): array
{
    $stmt = $conn->prepare(
        "SELECT g.*, r.full_name FROM grade_audit g LEFT JOIN reg_info r ON r.email = g.changed_by
         WHERE g.attempt_id = ? ORDER BY g.id DESC"
    );
    $stmt->bind_param("i", $attempt_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/** The stored answer of an attempt_answers row, in the shape grade_question() takes. */
function stored_answer_value(array $q, array $answer_row)
{
    switch ($q['type']) {
        case 'mcq':
        case 'true_false':
            return $answer_row['selected_option_id'] !== null ? (string) (int) $answer_row['selected_option_id'] : null;
        case 'mcq_multi':
            return array_map('strval', json_decode((string) ($answer_row['answer_json'] ?? ''), true) ?: []);
        case 'fill_blank':
            return json_decode((string) ($answer_row['answer_json'] ?? ''), true) ?: [];
        default:
            return $answer_row['answer_text'];
    }
}

/**
 * Re-grade a quiz's submitted attempts with the questions' current answer
 * keys, points and grading rules. Only answers no teacher has scored by hand
 * are changed; manual scores are always kept. The snapshot of each attempt
 * is updated to the new key so reviews show what it was graded against.
 * Every changed score is recorded in grade_audit.
 *
 * With $apply = false nothing is written: the result says what would change.
 *
 * @return array{attempts:int, changed:int, details:list<array>}
 */
function regrade_quiz(mysqli $conn, int $quiz_id, string $teacher_email, bool $apply): array
{
    $attempts = $conn->query(
        "SELECT * FROM quiz_attempts WHERE quiz_id = " . (int) $quiz_id . " AND status = 'submitted' AND is_practice = 0 ORDER BY id"
    )->fetch_all(MYSQLI_ASSOC);

    // Current keys of the quiz's live questions.
    $live = [];
    $ids = array_map('intval', array_column($conn->query(
        "SELECT id FROM questions WHERE quiz_id = " . (int) $quiz_id . " AND deleted_at IS NULL"
    )->fetch_all(MYSQLI_ASSOC), 'id'));
    foreach (build_snapshot_rows($conn, $ids) as $row) {
        $live[$row['question_id']] = $row;
    }

    $changed = 0;
    $details = [];
    foreach ($attempts as $attempt) {
        $snapshot = load_attempt_snapshot($conn, $attempt);
        $answers = [];
        foreach (attempt_review_rows($conn, $attempt) as $row) {
            $answers[(int) $row['question_id']] = $row;
        }
        $updates = [];
        $new_snapshot = [];
        foreach ($snapshot as $qid => $q) {
            if (!isset($live[$qid])) {
                $new_snapshot[$qid] = $q;
                continue;
            }
            // Keep this attempt's option order; take text and correctness from the live key.
            $live_opts = [];
            foreach ($live[$qid]['options'] as $o) {
                $live_opts[(int) $o['id']] = $o;
            }
            $options = [];
            foreach ($q['options'] as $o) {
                $options[] = isset($live_opts[(int) $o['id']]) ? $live_opts[(int) $o['id']] : $o;
            }
            $fresh = array_merge($q, [
                'points' => (float) $live[$qid]['points'],
                'model_answer' => $live[$qid]['model_answer'],
                'answer_config' => $live[$qid]['answer_config'],
                'options' => $options,
                'config' => null, // drop the old decoded rules so the new key is read
            ]);
            $fresh['config'] = question_config($fresh);
            $new_snapshot[$qid] = $fresh;

            $a = $answers[$qid] ?? null;
            if (!$a || $a['graded_by'] !== null) {
                continue; // no answer row, or scored by a teacher: keep
            }
            $result = grade_question($fresh, stored_answer_value($fresh, $a));
            $old = $a['points_awarded'] === null ? null : round((float) $a['points_awarded'], 2);
            $new = $result['points'] === null ? null : round($result['points'], 2);
            if ($old !== $new) {
                $updates[] = ['answer_id' => (int) $a['id'], 'qid' => $qid, 'old' => $old, 'new' => $new, 'is_correct' => $result['is_correct']];
            }
        }

        $changed += count($updates);
        foreach ($updates as $u) {
            $details[] = ['attempt_id' => (int) $attempt['id'], 'student' => $attempt['student_email']] + $u;
        }
        if (!$apply) {
            continue;
        }

        $conn->begin_transaction();
        try {
            $snap = $conn->prepare(
                "UPDATE attempt_questions SET points = ?, model_answer = ?, answer_config = ?, options_json = ?
                 WHERE attempt_id = ? AND question_id = ?"
            );
            foreach ($new_snapshot as $qid => $q) {
                if (!isset($live[$qid])) {
                    continue;
                }
                $opts = json_encode(array_values($q['options']), JSON_UNESCAPED_UNICODE);
                $pts = (float) $q['points'];
                $aid = (int) $attempt['id'];
                $snap->bind_param("dsssii", $pts, $q['model_answer'], $q['answer_config'], $opts, $aid, $qid);
                $snap->execute();
            }
            $snap->close();

            $upd = $conn->prepare(
                "UPDATE attempt_answers SET points_awarded = ?, is_correct = ?, graded_at = IF(? IS NULL, NULL, NOW())
                 WHERE id = ? AND graded_by IS NULL"
            );
            foreach ($updates as $u) {
                $upd->bind_param("didi", $u['new'], $u['is_correct'], $u['new'], $u['answer_id']);
                $upd->execute();
                record_grade_change($conn, (int) $attempt['id'], $u['answer_id'], $teacher_email, 'regrade', $u['old'], $u['new'], 'Re-graded with the current answer key');
            }
            $upd->close();

            $max = array_sum(array_map(fn($q) => (float) $q['points'], $new_snapshot));
            $aid = (int) $attempt['id'];
            $conn->query(
                "UPDATE quiz_attempts qa
                 JOIN (SELECT attempt_id, COALESCE(SUM(points_awarded), 0) AS total, SUM(points_awarded IS NULL) AS pending
                       FROM attempt_answers WHERE attempt_id = $aid GROUP BY attempt_id) s ON s.attempt_id = qa.id
                 SET qa.total_score = s.total, qa.needs_manual_grading = (s.pending > 0), qa.max_score = " . (float) $max . "
                 WHERE qa.id = $aid"
            );
            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
        }
    }
    return ['attempts' => count($attempts), 'changed' => $changed, 'details' => $details];
}
