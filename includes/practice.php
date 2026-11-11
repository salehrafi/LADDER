<?php
/**
 * Practice mode and bookmarks.
 *
 * Practice attempts (quiz_attempts.is_practice = 1) are unlimited, never
 * locked, timed only if the student asks, give instant feedback per
 * question, and never count towards grades, completion or analytics.
 *
 * A quiz can be practised when:
 *  - it is a practice-only quiz (mode 'practice') assigned to the student, or
 *  - it is an official quiz with practice_enabled, whose results and correct
 *    answers are already released (so practice can never leak answers to
 *    classmates still taking it).
 */

const BOOKMARK_NOTE_MAX = 500;

/** Can this student practise this quiz now? Returns null if yes, or the reason why not. */
function practice_block_reason(mysqli $conn, array $quiz, string $student_email): ?string
{
    if ($quiz['status'] === 'draft') {
        return 'This quiz is not published.';
    }
    if (!quiz_visible_to_student($conn, (int) $quiz['id'], $student_email)) {
        return 'This quiz is not assigned to you.';
    }
    if (($quiz['mode'] ?? 'official') === 'practice') {
        $window = student_window($conn, $quiz, $student_email);
        if ($window && $window['available_from'] && time() < strtotime($window['available_from'])) {
            return 'Practice opens ' . date('M j, Y g:i A', strtotime($window['available_from'])) . '.';
        }
        return null;
    }
    if ((int) ($quiz['practice_enabled'] ?? 0) !== 1) {
        return 'Your teacher has not turned on practice for this quiz.';
    }
    if (!answers_visible($quiz)) {
        return 'Practice opens once the results and answers are released.';
    }
    return null;
}

/** @return list<array> quizzes the student can practise, with their practice history */
function practice_quizzes(mysqli $conn, string $student_email): array
{
    $stmt = $conn->prepare(
        "SELECT q.* FROM quizzes q
         WHERE q.status IN ('published', 'closed') AND (q.mode = 'practice' OR q.practice_enabled = 1) AND " . quiz_visible_sql('q') . "
         ORDER BY q.title"
    );
    $stmt->bind_param("s", $student_email);
    $stmt->execute();
    $quizzes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $hist = $conn->prepare(
        "SELECT COUNT(*) AS n, MAX(total_score / NULLIF(max_score, 0)) AS best, MAX(submitted_at) AS last,
                MAX(CASE WHEN status = 'in_progress' THEN id END) AS open_id
         FROM quiz_attempts WHERE quiz_id = ? AND student_email = ? AND is_practice = 1"
    );
    foreach ($quizzes as &$q) {
        $q['block'] = practice_block_reason($conn, $q, $student_email);
        $id = (int) $q['id'];
        $hist->bind_param("is", $id, $student_email);
        $hist->execute();
        $h = $hist->get_result()->fetch_assoc();
        $q['practice_count'] = (int) $h['n'];
        $q['practice_best'] = $h['best'] !== null ? (float) $h['best'] : null;
        $q['practice_last'] = $h['last'];
        $q['practice_open_id'] = $h['open_id'] !== null ? (int) $h['open_id'] : null;
    }
    unset($q);
    $hist->close();
    return $quizzes;
}

/**
 * Instant feedback on one question of an in-progress practice attempt. The
 * answer is graded from the attempt's snapshot; nothing is stored.
 *
 * @return array{correct:?bool, points:?float, max:float, answer:string, explanation:string}|null null if not a question of this attempt
 */
function check_practice_answer(mysqli $conn, array $attempt, int $question_id, $raw): ?array
{
    if ((int) ($attempt['is_practice'] ?? 0) !== 1) {
        return null;
    }
    $snapshot = load_attempt_snapshot($conn, $attempt);
    if (!isset($snapshot[$question_id])) {
        return null;
    }
    $q = $snapshot[$question_id];
    $value = sanitize_answer_value($q, $raw);
    $result = grade_question($q, $value);
    return [
        'correct' => $result['points'] === null ? null : $result['points'] >= (float) $q['points'],
        'points' => $result['points'],
        'max' => (float) $q['points'],
        'answer' => correct_answer_summary($q),
        'explanation' => (string) ($q['explanation'] ?? ''),
    ];
}

// ---- Bookmarks -----------------------------------------------------------------------

/** Bookmark (or un-bookmark) a question of one of the student's submitted attempts. Returns true if now bookmarked. */
function toggle_bookmark(mysqli $conn, string $student_email, int $attempt_id, int $question_id): bool
{
    $attempt = load_attempt_for_student($conn, $attempt_id, $student_email, 'submitted');
    if (!$attempt || !isset(load_attempt_snapshot($conn, $attempt)[$question_id])) {
        throw new InvalidArgumentException('Question not found.');
    }
    $stmt = $conn->prepare("DELETE FROM question_bookmarks WHERE student_email = ? AND attempt_id = ? AND question_id = ?");
    $stmt->bind_param("sii", $student_email, $attempt_id, $question_id);
    $stmt->execute();
    $removed = $stmt->affected_rows > 0;
    $stmt->close();
    if ($removed) {
        return false;
    }
    $stmt = $conn->prepare("INSERT INTO question_bookmarks (student_email, attempt_id, question_id) VALUES (?, ?, ?)");
    $stmt->bind_param("sii", $student_email, $attempt_id, $question_id);
    $stmt->execute();
    $stmt->close();
    return true;
}

/** @return int[] question ids bookmarked in this attempt */
function attempt_bookmarks(mysqli $conn, string $student_email, int $attempt_id): array
{
    $stmt = $conn->prepare("SELECT question_id FROM question_bookmarks WHERE student_email = ? AND attempt_id = ?");
    $stmt->bind_param("si", $student_email, $attempt_id);
    $stmt->execute();
    $ids = array_map('intval', array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'question_id'));
    $stmt->close();
    return $ids;
}

function update_bookmark_note(mysqli $conn, string $student_email, int $bookmark_id, string $note): bool
{
    $note = trim($note);
    $note = $note === '' ? null : mb_substr($note, 0, BOOKMARK_NOTE_MAX);
    $stmt = $conn->prepare("UPDATE question_bookmarks SET note = ? WHERE id = ? AND student_email = ?");
    $stmt->bind_param("sis", $note, $bookmark_id, $student_email);
    $stmt->execute();
    $ok = $stmt->affected_rows >= 0;
    $stmt->close();
    return $ok;
}

function delete_bookmark(mysqli $conn, string $student_email, int $bookmark_id): bool
{
    $stmt = $conn->prepare("DELETE FROM question_bookmarks WHERE id = ? AND student_email = ?");
    $stmt->bind_param("is", $bookmark_id, $student_email);
    $stmt->execute();
    $ok = $stmt->affected_rows === 1;
    $stmt->close();
    return $ok;
}

/**
 * The student's bookmarks with the question as it was in that attempt, the
 * student's answer, and the correct answer when they may see it.
 *
 * @return list<array{id:int, note:?string, created_at:string, quiz_title:string, attempt:array, row:array, show_key:bool}>
 */
function student_bookmarks(mysqli $conn, string $student_email): array
{
    $stmt = $conn->prepare(
        "SELECT b.id, b.note, b.created_at, b.attempt_id, b.question_id, q.title AS quiz_title
         FROM question_bookmarks b
         JOIN quiz_attempts qa ON qa.id = b.attempt_id
         JOIN quizzes q ON q.id = qa.quiz_id
         WHERE b.student_email = ? ORDER BY b.created_at DESC, b.id DESC"
    );
    $stmt->bind_param("s", $student_email);
    $stmt->execute();
    $marks = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $out = [];
    $cache = [];
    foreach ($marks as $b) {
        $aid = (int) $b['attempt_id'];
        if (!isset($cache[$aid])) {
            $attempt = $conn->query(
                "SELECT qa.*, q.status AS quiz_status, q.end_time, q.results_release, q.results_released_at, q.show_answers
                 FROM quiz_attempts qa JOIN quizzes q ON q.id = qa.quiz_id WHERE qa.id = $aid"
            )->fetch_assoc();
            $rows = [];
            foreach (attempt_review_rows($conn, $attempt) as $r) {
                $rows[(int) $r['question_id']] = $r;
            }
            $quiz_view = ['status' => $attempt['quiz_status']] + $attempt;
            $cache[$aid] = [$attempt, $rows, (int) $attempt['is_practice'] === 1 || answers_visible($quiz_view)];
        }
        [$attempt, $rows, $show_key] = $cache[$aid];
        if (!isset($rows[(int) $b['question_id']])) {
            continue;
        }
        $out[] = [
            'id' => (int) $b['id'], 'note' => $b['note'], 'created_at' => $b['created_at'],
            'quiz_title' => $b['quiz_title'], 'attempt' => $attempt, 'row' => $rows[(int) $b['question_id']], 'show_key' => $show_key,
        ];
    }
    return $out;
}
