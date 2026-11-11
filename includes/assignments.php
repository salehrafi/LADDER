<?php
/**
 * Quiz assignments: who a quiz is for and when.
 *
 * An assignment targets all of the teacher's students, one section, or one
 * student, with an optional window: available_from, due_at (deadline) and
 * late_until (late submissions accepted until). Empty dates fall back to the
 * quiz's start_time / end_time. A quiz with no assignments is open to all of
 * the teacher's students (the behaviour before assignments existed).
 *
 * When several assignments match a student, the most specific one wins
 * (student > section > all); among equals, the most generous deadline.
 * The server decides availability; nothing here trusts the browser.
 */

const ASSIGNMENT_TARGETS = ['all' => 'All my students', 'section' => 'Sections', 'student' => 'Selected students'];

const STATUS_LABELS = [
    'not_started' => 'Not started',
    'in_progress' => 'In progress',
    'completed' => 'Completed',
    'completed_late' => 'Completed late',
    'overdue' => 'Overdue',
    'upcoming' => 'Not open yet',
];

const STATUS_BADGES = [
    'not_started' => 'badge-draft',
    'in_progress' => 'badge-info',
    'completed' => 'badge-open',
    'completed_late' => 'badge-pending',
    'overdue' => 'badge-closed',
    'upcoming' => 'badge-draft',
];

/** Was this attempt submitted after the deadline that applied when it started? */
function attempt_is_late(array $attempt): bool
{
    return !empty($attempt['due_at']) && !empty($attempt['submitted_at'])
        && strtotime($attempt['submitted_at']) > strtotime($attempt['due_at']);
}

/** "2026-11-09T14:30" from a datetime-local field → "2026-11-09 14:30:00", or null. */
function form_datetime(?string $value): ?string
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }
    $ts = strtotime(str_replace('T', ' ', $value));
    return $ts === false ? null : date('Y-m-d H:i:s', $ts);
}

/** Value for a datetime-local input. */
function input_datetime(?string $value): string
{
    return $value ? date('Y-m-d\TH:i', strtotime($value)) : '';
}

/** @return array<int,array> a quiz's assignments with the section or student name */
function quiz_assignment_list(mysqli $conn, int $quiz_id): array
{
    $stmt = $conn->prepare(
        "SELECT a.*, s.name AS section_name, r.full_name AS student_name
         FROM quiz_assignments a
         LEFT JOIN sections s ON s.id = a.section_id
         LEFT JOIN reg_info r ON r.email = a.student_email
         WHERE a.quiz_id = ?
         ORDER BY FIELD(a.target_type, 'all', 'section', 'student'), s.name, r.full_name"
    );
    $stmt->bind_param("i", $quiz_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/**
 * Assign a quiz. $targets are section ids or student emails depending on the
 * type (ignored for 'all'). Only the teacher's own sections and students are
 * accepted. Re-assigning the same target updates its window.
 *
 * @return int number of assignments created or updated
 */
function assign_quiz(mysqli $conn, string $teacher_email, int $quiz_id, string $type, array $targets, ?string $from, ?string $due, ?string $late): int
{
    $stmt = $conn->prepare("SELECT id FROM quizzes WHERE id = ? AND teacher_email = ?");
    $stmt->bind_param("is", $quiz_id, $teacher_email);
    $stmt->execute();
    $owned = $stmt->get_result()->num_rows === 1;
    $stmt->close();
    if (!$owned) {
        throw new InvalidArgumentException('Quiz not found.');
    }
    if (!isset(ASSIGNMENT_TARGETS[$type])) {
        throw new InvalidArgumentException('Choose who the quiz is for.');
    }
    if ($from && $due && strtotime($from) >= strtotime($due)) {
        throw new InvalidArgumentException('The due date must be after the opening date.');
    }
    if ($late && !$due) {
        throw new InvalidArgumentException('Set a due date before allowing late submissions.');
    }
    if ($late && strtotime($late) <= strtotime($due)) {
        throw new InvalidArgumentException('"Late submissions until" must be after the due date.');
    }

    $rows = [];
    if ($type === 'all') {
        $rows[] = [null, null];
    } elseif ($type === 'section') {
        $own = array_map('intval', array_column(teacher_sections($conn, $teacher_email), 'id'));
        foreach (array_unique(array_map('intval', $targets)) as $sid) {
            if (in_array($sid, $own, true)) {
                $rows[] = [$sid, null];
            }
        }
    } else {
        foreach (array_unique(array_map('strval', $targets)) as $email) {
            if (teacher_has_student($conn, $teacher_email, $email)) {
                $rows[] = [null, $email];
            }
        }
    }
    if (!$rows) {
        throw new InvalidArgumentException($type === 'section' ? 'Tick at least one of your sections.' : 'Tick at least one of your students.');
    }

    // UNIQUE treats NULLs as distinct, so look for an existing row first.
    $find = $conn->prepare(
        "SELECT id FROM quiz_assignments WHERE quiz_id = ? AND target_type = ?
         AND section_id <=> ? AND student_email <=> ?"
    );
    $upd = $conn->prepare("UPDATE quiz_assignments SET available_from = ?, due_at = ?, late_until = ? WHERE id = ?");
    $ins = $conn->prepare(
        "INSERT INTO quiz_assignments (quiz_id, target_type, section_id, student_email, available_from, due_at, late_until)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    );
    foreach ($rows as [$sid, $email]) {
        $find->bind_param("isis", $quiz_id, $type, $sid, $email);
        $find->execute();
        $existing = $find->get_result()->fetch_assoc();
        if ($existing) {
            $id = (int) $existing['id'];
            $upd->bind_param("sssi", $from, $due, $late, $id);
            $upd->execute();
        } else {
            $ins->bind_param("isissss", $quiz_id, $type, $sid, $email, $from, $due, $late);
            $ins->execute();
        }
    }
    $find->close();
    $upd->close();
    $ins->close();
    return count($rows);
}

/** Remove one assignment of the teacher's quiz. */
function remove_assignment(mysqli $conn, string $teacher_email, int $assignment_id): bool
{
    $stmt = $conn->prepare(
        "DELETE a FROM quiz_assignments a JOIN quizzes q ON q.id = a.quiz_id
         WHERE a.id = ? AND q.teacher_email = ?"
    );
    $stmt->bind_param("is", $assignment_id, $teacher_email);
    $stmt->execute();
    $ok = $stmt->affected_rows === 1;
    $stmt->close();
    return $ok;
}

/**
 * The window that applies to this student for this quiz, or null if the quiz
 * is not assigned to them. Dates fall back to the quiz's own times.
 *
 * @return array{available_from:?string, due_at:?string, late_until:?string, source:string}|null
 */
function student_window(mysqli $conn, array $quiz, string $student_email): ?array
{
    $quiz_id = (int) $quiz['id'];
    $stmt = $conn->prepare(
        "SELECT a.target_type, a.available_from, a.due_at, a.late_until
         FROM quiz_assignments a
         WHERE a.quiz_id = ? AND (
            a.target_type = 'all'
            OR (a.target_type = 'student' AND a.student_email = ?)
            OR (a.target_type = 'section' AND EXISTS (
                SELECT 1 FROM section_students ss WHERE ss.section_id = a.section_id AND ss.student_email = ?)))"
    );
    $stmt->bind_param("iss", $quiz_id, $student_email, $student_email);
    $stmt->execute();
    $matches = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (!$matches) {
        $any = $conn->query("SELECT 1 FROM quiz_assignments WHERE quiz_id = $quiz_id LIMIT 1")->num_rows > 0;
        if ($any) {
            return null; // assigned to others only
        }
        $matches = [['target_type' => 'none', 'available_from' => null, 'due_at' => null, 'late_until' => null]];
    }

    $rank = ['student' => 3, 'section' => 2, 'all' => 1, 'none' => 0];
    usort($matches, function ($a, $b) use ($rank) {
        if ($rank[$a['target_type']] !== $rank[$b['target_type']]) {
            return $rank[$b['target_type']] <=> $rank[$a['target_type']];
        }
        // Equal specificity: the most generous deadline first (no deadline = most generous).
        $da = $a['due_at'] === null ? PHP_INT_MAX : strtotime($a['due_at']);
        $db = $b['due_at'] === null ? PHP_INT_MAX : strtotime($b['due_at']);
        return $db <=> $da;
    });
    $best = $matches[0];
    return [
        'available_from' => $best['available_from'] ?? $quiz['start_time'] ?? null,
        'due_at' => $best['due_at'] ?? $quiz['end_time'] ?? null,
        'late_until' => $best['due_at'] !== null ? $best['late_until'] : null,
        'source' => $best['target_type'],
    ];
}

/**
 * Can the student start now, and what to show? Extends quiz_availability()
 * with the assignment window and late submissions.
 *
 * @return array{open:bool, late:bool, label:string, state:string}
 */
function window_availability(array $quiz, ?array $window, ?int $now = null): array
{
    $now = $now ?? time();
    if ($window === null) {
        return ['open' => false, 'late' => false, 'label' => 'Not assigned to you', 'state' => 'unassigned'];
    }
    if ($quiz['status'] === 'draft') {
        return ['open' => false, 'late' => false, 'label' => 'Draft (not published)', 'state' => 'draft'];
    }
    if ($quiz['status'] === 'closed') {
        return ['open' => false, 'late' => false, 'label' => 'Closed', 'state' => 'closed'];
    }
    if ($window['available_from'] && $now < strtotime($window['available_from'])) {
        return ['open' => false, 'late' => false, 'label' => 'Opens ' . date('M j, Y g:i A', strtotime($window['available_from'])), 'state' => 'upcoming'];
    }
    if ($window['due_at'] && $now > strtotime($window['due_at'])) {
        if ($window['late_until'] && $now <= strtotime($window['late_until'])) {
            return ['open' => true, 'late' => true, 'label' => 'Overdue: late submissions accepted until ' . date('M j, Y g:i A', strtotime($window['late_until'])), 'state' => 'late'];
        }
        return ['open' => false, 'late' => false, 'label' => 'Closed (deadline passed)', 'state' => 'past'];
    }
    if ($window['due_at']) {
        return ['open' => true, 'late' => false, 'label' => 'Due ' . date('M j, Y g:i A', strtotime($window['due_at'])), 'state' => 'open'];
    }
    return ['open' => true, 'late' => false, 'label' => 'Open', 'state' => 'open'];
}

/**
 * A student's status on an assigned quiz.
 *
 * @param array $summary [submitted => int, in_progress => bool, late => bool]
 */
function student_quiz_status(?array $window, array $summary, ?int $now = null): string
{
    $now = $now ?? time();
    if ((int) $summary['submitted'] > 0) {
        return !empty($summary['late']) ? 'completed_late' : 'completed';
    }
    if (!empty($summary['in_progress'])) {
        return 'in_progress';
    }
    if ($window && $window['available_from'] && $now < strtotime($window['available_from'])) {
        return 'upcoming';
    }
    if ($window && $window['due_at'] && $now > strtotime($window['due_at'])) {
        return 'overdue';
    }
    return 'not_started';
}

/** Attempt summary of one student on one quiz (official attempts only). */
function student_attempt_summary(mysqli $conn, int $quiz_id, string $student_email): array
{
    $stmt = $conn->prepare(
        "SELECT SUM(status = 'submitted') AS submitted, SUM(status = 'in_progress') AS in_progress,
                SUM(status = 'submitted' AND due_at IS NOT NULL AND submitted_at > due_at) AS late,
                MAX(CASE WHEN status = 'submitted' THEN total_score / NULLIF(max_score, 0) END) AS best_pct,
                MAX(submitted_at) AS last_submitted
         FROM quiz_attempts WHERE quiz_id = ? AND student_email = ? AND is_practice = 0"
    );
    $stmt->bind_param("is", $quiz_id, $student_email);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return [
        'submitted' => (int) ($r['submitted'] ?? 0),
        'in_progress' => (int) ($r['in_progress'] ?? 0) > 0,
        'late' => (int) ($r['late'] ?? 0) > 0,
        'best_pct' => $r['best_pct'] !== null ? (float) $r['best_pct'] : null,
        'last_submitted' => $r['last_submitted'],
    ];
}

/**
 * Everyone a quiz is assigned to, with their window and status: the
 * teacher's completion tracker.
 *
 * @return list<array{email:string, name:string, sections:string, window:?array, summary:array, status:string}>
 */
function assignment_roster(mysqli $conn, string $teacher_email, array $quiz): array
{
    $quiz_id = (int) $quiz['id'];
    $assignments = quiz_assignment_list($conn, $quiz_id);
    $everyone = !$assignments || in_array('all', array_column($assignments, 'target_type'), true);

    $emails = [];
    if ($everyone) {
        $stmt = $conn->prepare(
            "SELECT DISTINCT ss.student_email FROM section_students ss JOIN sections s ON s.id = ss.section_id WHERE s.teacher_email = ?"
        );
        $stmt->bind_param("s", $teacher_email);
        $stmt->execute();
        $emails = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'student_email');
        $stmt->close();
    }
    foreach ($assignments as $a) {
        if ($a['target_type'] === 'section') {
            $stmt = $conn->prepare("SELECT student_email FROM section_students WHERE section_id = ?");
            $sid = (int) $a['section_id'];
            $stmt->bind_param("i", $sid);
            $stmt->execute();
            $emails = array_merge($emails, array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'student_email'));
            $stmt->close();
        } elseif ($a['target_type'] === 'student') {
            $emails[] = $a['student_email'];
        }
    }
    // Students who took the quiz before an assignment changed still appear.
    $res = $conn->query("SELECT DISTINCT student_email FROM quiz_attempts WHERE quiz_id = $quiz_id AND is_practice = 0");
    $emails = array_values(array_unique(array_merge($emails, array_column($res->fetch_all(MYSQLI_ASSOC), 'student_email'))));

    $names = section_names_by_student($conn, $teacher_email);
    $out = [];
    foreach ($emails as $email) {
        $user = find_user($conn, $email);
        if (!$user) {
            continue;
        }
        $window = student_window($conn, $quiz, $email);
        $summary = student_attempt_summary($conn, $quiz_id, $email);
        $out[] = [
            'email' => $email,
            'name' => $user['full_name'] ?? $email,
            'sections' => $names[$email] ?? '',
            'window' => $window,
            'summary' => $summary,
            'status' => student_quiz_status($window, $summary),
        ];
    }
    usort($out, fn($a, $b) => strcasecmp($a['name'], $b['name']));
    return $out;
}
