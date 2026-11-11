<?php
/**
 * In-app notifications, with optional email.
 *
 * Every event writes one row per recipient. dedupe_key makes repeated
 * triggers of the same event harmless (INSERT IGNORE on user + key), so
 * "lazy" checks that run on page loads (deadlines, results that became
 * visible because a quiz closed) can be called freely.
 *
 * Email is opt-in per user (Settings). Emails are queued: a few are sent at
 * the end of a request, and `php db/send_notifications.php` sends the rest
 * (for a cron job where the host has one).
 */

use PHPMailer\PHPMailer\PHPMailer;

const NOTIFICATION_TYPES = [
    'assigned' => 'Quiz assigned',
    'due_soon' => 'Deadline soon',
    'results' => 'Results published',
    'feedback' => 'Teacher feedback',
    'pending' => 'Answers to evaluate',
];

const EMAILS_PER_REQUEST = 3;

/**
 * Notify one or more users. $link is relative to the app root.
 *
 * @param string|string[] $emails
 * @return int notifications created (duplicates of the same dedupe key are skipped)
 */
function notify(mysqli $conn, $emails, string $type, string $title, ?string $body = null, ?string $link = null, ?string $dedupe = null): int
{
    $stmt = $conn->prepare(
        "INSERT IGNORE INTO notifications (user_email, type, title, body, link, dedupe_key)
         SELECT email, ?, ?, ?, ?, ? FROM reg_info WHERE email = ?"
    );
    $title = mb_substr($title, 0, 200);
    $body = $body === null ? null : mb_substr($body, 0, 500);
    $created = 0;
    foreach (array_unique((array) $emails) as $email) {
        $email = (string) $email;
        $stmt->bind_param("ssssss", $type, $title, $body, $link, $dedupe, $email);
        $stmt->execute();
        $created += $stmt->affected_rows;
    }
    $stmt->close();
    if ($created > 0) {
        schedule_email_delivery();
    }
    return $created;
}

function unread_notification_count(mysqli $conn, string $email): int
{
    $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM notifications WHERE user_email = ? AND read_at IS NULL");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $n = (int) $stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();
    return $n;
}

/** @return array{rows:list<array>, total:int} newest first */
function user_notifications(mysqli $conn, string $email, int $limit = 30, int $offset = 0, bool $unread_only = false): array
{
    $where = "user_email = ?" . ($unread_only ? " AND read_at IS NULL" : "");
    $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM notifications WHERE $where");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $total = (int) $stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();
    $stmt = $conn->prepare("SELECT * FROM notifications WHERE $where ORDER BY id DESC LIMIT " . (int) $limit . " OFFSET " . (int) $offset);
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return ['rows' => $rows, 'total' => $total];
}

/** Mark one notification (or all, $id = null) of this user as read. */
function mark_notifications_read(mysqli $conn, string $email, ?int $id = null): void
{
    if ($id === null) {
        $stmt = $conn->prepare("UPDATE notifications SET read_at = NOW() WHERE user_email = ? AND read_at IS NULL");
        $stmt->bind_param("s", $email);
    } else {
        $stmt = $conn->prepare("UPDATE notifications SET read_at = COALESCE(read_at, NOW()) WHERE user_email = ? AND id = ?");
        $stmt->bind_param("si", $email, $id);
    }
    $stmt->execute();
    $stmt->close();
}

/** A notification of this user, or null. */
function find_notification(mysqli $conn, string $email, int $id): ?array
{
    $stmt = $conn->prepare("SELECT * FROM notifications WHERE id = ? AND user_email = ?");
    $stmt->bind_param("is", $id, $email);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

// ---- Events ------------------------------------------------------------------------

/** A published quiz was assigned: tell every student it now applies to. */
function notify_quiz_assigned(mysqli $conn, string $teacher_email, int $quiz_id): int
{
    $quiz = $conn->query("SELECT * FROM quizzes WHERE id = " . (int) $quiz_id)->fetch_assoc();
    if (!$quiz || $quiz['status'] !== 'published') {
        return 0; // drafts notify when they are published
    }
    $created = 0;
    foreach (assignment_roster($conn, $teacher_email, $quiz) as $s) {
        if ($s['window'] === null || $s['summary']['submitted'] > 0) {
            continue;
        }
        $due = $s['window']['due_at'] ? ' Due ' . date('M j, Y g:i A', strtotime($s['window']['due_at'])) . '.' : '';
        $created += notify($conn, $s['email'], 'assigned', 'New quiz: ' . $quiz['title'],
            'Your teacher assigned you a quiz.' . $due, 'dashboards/studentPages/quizzes.php', 'assigned:' . $quiz_id);
    }
    return $created;
}

/**
 * Due within 24 hours and not submitted: remind the student (once per
 * deadline). Called when the student's quiz list or dashboard loads.
 *
 * @param array $quizzes rows with 'window', 'avail', 'summary' (see quizzes.php)
 */
function notify_upcoming_deadlines(mysqli $conn, string $student_email, array $quizzes): void
{
    $now = time();
    foreach ($quizzes as $q) {
        $due = $q['window']['due_at'] ?? null;
        if (!$due || empty($q['avail']['open']) || ($q['summary']['submitted'] ?? 0) > 0) {
            continue;
        }
        $left = strtotime($due) - $now;
        if ($left > 0 && $left <= 86400) {
            notify($conn, $student_email, 'due_soon', 'Due soon: ' . $q['title'],
                'Due ' . date('M j, Y g:i A', strtotime($due)) . '. You have not submitted it yet.',
                'dashboards/studentPages/quizzes.php', 'due:' . (int) $q['id'] . ':' . $due);
        }
    }
}

/** Results of a quiz became visible: tell every student who submitted it. */
function notify_results_released(mysqli $conn, int $quiz_id): int
{
    $quiz = $conn->query("SELECT * FROM quizzes WHERE id = " . (int) $quiz_id)->fetch_assoc();
    if (!$quiz || !results_released($quiz)) {
        return 0;
    }
    $students = array_column($conn->query(
        "SELECT DISTINCT student_email FROM quiz_attempts WHERE quiz_id = " . (int) $quiz_id . " AND status = 'submitted' AND is_practice = 0"
    )->fetch_all(MYSQLI_ASSOC), 'student_email');
    $stamp = $quiz['results_released_at'] ?: ($quiz['status'] === 'closed' ? 'closed' : 'end');
    return notify($conn, $students, 'results', 'Results published: ' . $quiz['title'],
        'Your score and feedback are now available.', 'dashboards/studentPages/results.php?quiz_id=' . (int) $quiz_id,
        'results:' . (int) $quiz_id . ':' . $stamp);
}

/**
 * Results that became visible because a quiz's end time passed have no
 * teacher action to hang a notification on: check when the student looks.
 */
function notify_newly_visible_results(mysqli $conn, string $student_email): void
{
    $stmt = $conn->prepare(
        "SELECT DISTINCT q.id, q.title FROM quiz_attempts qa JOIN quizzes q ON q.id = qa.quiz_id
         WHERE qa.student_email = ? AND qa.status = 'submitted' AND qa.is_practice = 0
           AND q.results_release = 'after_close' AND q.results_released_at IS NULL
           AND q.status <> 'closed' AND q.end_time IS NOT NULL AND q.end_time < NOW()"
    );
    $stmt->bind_param("s", $student_email);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $q) {
        notify($conn, $student_email, 'results', 'Results published: ' . $q['title'],
            'Your score and feedback are now available.', 'dashboards/studentPages/results.php?quiz_id=' . (int) $q['id'],
            'results:' . (int) $q['id'] . ':end');
    }
    $stmt->close();
}

/** The teacher wrote feedback on a released attempt. */
function notify_feedback(mysqli $conn, array $attempt): int
{
    $quiz = $conn->query("SELECT * FROM quizzes WHERE id = " . (int) $attempt['quiz_id'])->fetch_assoc();
    if (!$quiz || !results_released($quiz)) {
        return 0; // the release notification will cover it
    }
    return notify($conn, $attempt['student_email'], 'feedback', 'New feedback: ' . $quiz['title'],
        'Your teacher left feedback on your answers.', 'dashboards/studentPages/view_attempt.php?attempt_id=' . (int) $attempt['id'],
        'feedback:' . (int) $attempt['id'] . ':' . date('YmdHi'));
}

/** A submitted attempt needs hand grading: tell the teacher (once per quiz per day). */
function notify_pending_evaluation(mysqli $conn, int $attempt_id): int
{
    $row = $conn->query(
        "SELECT qa.needs_manual_grading, qa.is_practice, q.id AS quiz_id, q.title, q.teacher_email
         FROM quiz_attempts qa JOIN quizzes q ON q.id = qa.quiz_id WHERE qa.id = " . (int) $attempt_id
    )->fetch_assoc();
    if (!$row || (int) $row['needs_manual_grading'] !== 1 || (int) $row['is_practice'] === 1) {
        return 0;
    }
    return notify($conn, $row['teacher_email'], 'pending', 'Answers to evaluate: ' . $row['title'],
        'A student submitted answers that need your grading.', 'dashboards/teachersPages/results.php',
        'pending:' . (int) $row['quiz_id'] . ':' . date('Y-m-d'));
}

// ---- Email ---------------------------------------------------------------------------

/** Is outgoing mail configured in .env? */
function mail_configured(): bool
{
    return env_value('email', '') !== '' && env_value('password', '') !== '';
}

/** Send one email with the app's SMTP settings. Returns false on any failure (logged). */
function send_app_mail(string $to, string $subject, string $html, string $text): bool
{
    if (!mail_configured()) {
        return false;
    }
    require_once APP_ROOT . '/PHPMailer/src/Exception.php';
    require_once APP_ROOT . '/PHPMailer/src/PHPMailer.php';
    require_once APP_ROOT . '/PHPMailer/src/SMTP.php';
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = env_value('smtp_host', 'smtp.gmail.com');
        $mail->SMTPAuth = true;
        $mail->SMTPSecure = 'tls';
        $mail->Port = (int) env_value('smtp_port', 587);
        $mail->Timeout = 10;
        $mail->Username = env_value('email', '');
        $mail->Password = env_value('password', '');
        $mail->setFrom(env_value('email', ''), 'LADDER');
        $mail->addAddress($to);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $html;
        $mail->AltBody = $text;
        $mail->send();
        return true;
    } catch (Throwable $e) {
        error_log('Email failed: ' . $mail->ErrorInfo);
        return false;
    }
}

/**
 * Send queued emails for users who opted in. Each notification is emailed at
 * most once (emailed_at is set even if sending fails, so a broken mailbox
 * cannot cause endless retries; the in-app notification is still there).
 *
 * @return int emails attempted
 */
function process_email_queue(mysqli $conn, int $limit = EMAILS_PER_REQUEST): int
{
    if (!mail_configured()) {
        return 0;
    }
    $res = $conn->query(
        "SELECT n.* FROM notifications n JOIN reg_info r ON r.email = n.user_email
         WHERE r.email_notifications = 1 AND n.emailed_at IS NULL AND n.created_at > NOW() - INTERVAL 3 DAY
         ORDER BY n.id LIMIT " . (int) $limit
    );
    $rows = $res->fetch_all(MYSQLI_ASSOC);
    $done = $conn->prepare("UPDATE notifications SET emailed_at = NOW() WHERE id = ?");
    $base = rtrim((string) env_value('APP_URL', ''), '/');
    foreach ($rows as $n) {
        $id = (int) $n['id'];
        $done->bind_param("i", $id);
        $done->execute(); // claim first, so two requests never send the same email
        $link = ($base !== '' && $n['link']) ? $base . '/' . ltrim($n['link'], '/') : '';
        $html = '<div style="font-family:Arial,sans-serif;line-height:1.6;color:#172033">'
            . '<p style="font-size:16px;font-weight:bold;color:#142B4A">' . e($n['title']) . '</p>'
            . ($n['body'] ? '<p>' . e($n['body']) . '</p>' : '')
            . ($link ? '<p><a href="' . e($link) . '" style="color:#2563EB">Open LADDER</a></p>' : '')
            . '<p style="color:#64748B;font-size:12px">You get these emails because you turned them on in Settings.</p></div>';
        send_app_mail($n['user_email'], $n['title'], $html, $n['title'] . ($n['body'] ? "\n\n" . $n['body'] : '') . ($link ? "\n\n$link" : ''));
    }
    $done->close();
    return count($rows);
}

/** Send a few queued emails after this request's response, if anyone opted in. */
function schedule_email_delivery(): void
{
    static $scheduled = false;
    if ($scheduled || PHP_SAPI === 'cli' || !mail_configured()) {
        return;
    }
    $scheduled = true;
    register_shutdown_function(function () {
        global $conn;
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        if ($conn instanceof mysqli) {
            try {
                process_email_queue($conn);
            } catch (Throwable $e) {
                error_log('Email queue failed: ' . $e->getMessage());
            }
        }
    });
}
