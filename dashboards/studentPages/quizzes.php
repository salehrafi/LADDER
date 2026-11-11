<?php
require_once __DIR__ . '/../../includes/bootstrap.php';

require_role('student', '../../auth/login.php');

$student_email = current_user_email();

// Quizzes assigned to this student (all students, their section, or them).
$stmt = $conn->prepare("SELECT q.* FROM quizzes q WHERE q.status = 'published' AND q.mode = 'official' AND " . quiz_visible_sql('q') . " ORDER BY q.created_at DESC");
$stmt->bind_param("s", $student_email);
$stmt->execute();
$quizzes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

foreach ($quizzes as &$q) {
    $q['window'] = student_window($conn, $q, $student_email);
    $q['avail'] = window_availability($q, $q['window']);
    $q['summary'] = student_attempt_summary($conn, (int) $q['id'], $student_email);
    $q['status'] = student_quiz_status($q['window'], $q['summary']);
    $q['attempts_used'] = $q['summary']['submitted'];
}
unset($q);

// Open quizzes first, soonest deadline first; then the rest.
usort($quizzes, function ($a, $b) {
    if ($a['avail']['open'] !== $b['avail']['open']) {
        return $a['avail']['open'] ? -1 : 1;
    }
    $da = $a['window']['due_at'] ?? null;
    $db = $b['window']['due_at'] ?? null;
    return ($da ? strtotime($da) : PHP_INT_MAX) <=> ($db ? strtotime($db) : PHP_INT_MAX);
});

notify_upcoming_deadlines($conn, $student_email, $quizzes);

$active = 'quizzes';
$nav_base = '../';
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../navStyle.css?v=9">
    <link rel="stylesheet" href="../appStyle.css?v=9">
    <title>Quizzes · LADDER</title>
</head>

<body>
    <?php include APP_ROOT . '/dashboards/partials/nav_student.php'; ?>

    <div class="container" id="main">
        <div class="page-header">
            <div>
                <h1>My Quizzes</h1>
                <p class="subtitle">Quizzes your teachers have assigned to you. Start or resume one below.</p>
            </div>
        </div>

        <div class="card">
            <?php if (empty($quizzes)): ?>
                <div class="empty-state">No quizzes are assigned to you yet. Your teacher will add you to a class and assign quizzes.</div>
            <?php else: ?>
                <div class="card-list">
                    <?php foreach ($quizzes as $q):
                        $avail = $q['avail'];
                        $unlimited = (int) $q['max_attempts'] === 0;
                        $has_attempts_left = $unlimited || $q['attempts_used'] < (int) $q['max_attempts'];
                        $can_start = $avail['open'] && $has_attempts_left;
                    ?>
                        <div class="item-row">
                            <div>
                                <div class="item-title"><?php echo e($q['title']); ?></div>
                                <div class="item-meta">
                                    <?php echo $q['time_limit_minutes'] ? (int) $q['time_limit_minutes'] . ' min limit' : 'No time limit'; ?>
                                    &middot; Attempts: <?php echo $q['attempts_used']; ?> / <?php echo attempts_label((int) $q['max_attempts']); ?>
                                    <?php if ((int) ($q['lock_on_leave'] ?? 1) === 1): ?>&middot; <span class="lock-note">Exam mode: leaving the page submits</span><?php endif; ?>
                                </div>
                                <div class="item-meta"><?php echo icon('clock'); ?> <?php echo e($avail['label']); ?></div>
                                <?php if ($q['description']): ?><div class="item-meta"><?php echo e($q['description']); ?></div><?php endif; ?>
                            </div>
                            <div class="item-actions">
                                <span class="badge <?php echo STATUS_BADGES[$q['status']]; ?>"><?php echo e(STATUS_LABELS[$q['status']]); ?></span>
                                <?php if ($q['summary']['in_progress']): ?>
                                    <a class="btn btn-sm" href="take_quiz.php?quiz_id=<?php echo (int) $q['id']; ?>">Resume</a>
                                <?php elseif ($can_start): ?>
                                    <a class="btn btn-sm" href="take_quiz.php?quiz_id=<?php echo (int) $q['id']; ?>&amp;start=1"
                                       <?php if ((int) ($q['lock_on_leave'] ?? 1) === 1): ?>data-confirm="This quiz runs in exam mode. Once you start, switching tabs or apps, minimising, locking your screen, reloading or closing the page will submit it automatically. Start now?"<?php endif; ?>><?php echo $avail['late'] ? 'Start (late)' : 'Start'; ?></a>
                                <?php elseif ($avail['open'] && !$has_attempts_left): ?>
                                    <span class="badge badge-pending">No attempts left</span>
                                <?php endif; ?>
                                <?php if ($q['attempts_used'] > 0): ?>
                                    <a class="btn btn-outline btn-sm" href="results.php?quiz_id=<?php echo (int) $q['id']; ?>">My results</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>

</html>
