<?php
require_once __DIR__ . '/../includes/bootstrap.php';

require_role('student', '../auth/login.php');

$student_email = current_user_email();

// --- Stats -------------------------------------------------------------
// Only quizzes for this student's sections (or open to everyone).
$stmt = $conn->prepare("SELECT COUNT(*) AS c FROM quizzes q WHERE q.status = 'published' AND q.mode = 'official' AND " . quiz_visible_sql('q'));
$stmt->bind_param("s", $student_email);
$stmt->execute();
$published_total = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();

$stmt = $conn->prepare("SELECT COUNT(DISTINCT quiz_id) AS c FROM quiz_attempts WHERE student_email = ? AND status = 'submitted' AND is_practice = 0");
$stmt->bind_param("s", $student_email);
$stmt->execute();
$quizzes_completed = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();

$stmt = $conn->prepare(
    "SELECT AVG(qa.total_score / NULLIF(qa.max_score, 0)) AS avg_pct
     FROM quiz_attempts qa JOIN quizzes q ON q.id = qa.quiz_id
     WHERE qa.student_email = ? AND qa.status = 'submitted' AND qa.is_practice = 0 AND qa.needs_manual_grading = 0 AND " . results_released_sql('q')
);
$stmt->bind_param("s", $student_email);
$stmt->execute();
$avg_pct_row = $stmt->get_result()->fetch_assoc();
$avg_pct = $avg_pct_row['avg_pct'] !== null ? round(((float) $avg_pct_row['avg_pct']) * 100, 1) : null;
$stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) AS c FROM quiz_attempts WHERE student_email = ? AND needs_manual_grading = 1 AND is_practice = 0");
$stmt->bind_param("s", $student_email);
$stmt->execute();
$pending_review = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();

// Assigned quizzes with this student's window and status: the open ones not
// yet submitted, soonest deadline first.
$stmt = $conn->prepare("SELECT q.* FROM quizzes q WHERE q.status = 'published' AND q.mode = 'official' AND " . quiz_visible_sql('q'));
$stmt->bind_param("s", $student_email);
$stmt->execute();
$assigned = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
foreach ($assigned as &$q) {
    $q['window'] = student_window($conn, $q, $student_email);
    $q['avail'] = window_availability($q, $q['window']);
    $q['summary'] = student_attempt_summary($conn, (int) $q['id'], $student_email);
    $q['status'] = student_quiz_status($q['window'], $q['summary']);
}
unset($q);
$upcoming = array_values(array_filter($assigned, fn($q) => $q['avail']['open'] && $q['summary']['submitted'] === 0));
usort($upcoming, fn($a, $b) => (($a['window']['due_at'] ?? null) ? strtotime($a['window']['due_at']) : PHP_INT_MAX)
    <=> (($b['window']['due_at'] ?? null) ? strtotime($b['window']['due_at']) : PHP_INT_MAX));
$upcoming = array_slice($upcoming, 0, 6);
$overdue_count = count(array_filter($assigned, fn($q) => $q['status'] === 'overdue'));

// Progress snapshot (released, graded official attempts only).
$trend = student_score_trend($conn, $student_email, 10);
$focus = student_strengths($conn, $student_email, 1)['weak'][0] ?? null;

// Reminders and results that became visible without a teacher action.
notify_upcoming_deadlines($conn, $student_email, $assigned);
notify_newly_visible_results($conn, $student_email);

$active = 'home';
$nav_base = '';
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="navStyle.css?v=9">
    <link rel="stylesheet" href="appStyle.css?v=10">
    <title>Student Dashboard</title>
</head>

<body>
    <?php include APP_ROOT . '/dashboards/partials/nav_student.php'; ?>

    <div class="container" id="main">
        <div class="page-header">
            <div>
                <h1>Welcome, <?php echo e($_SESSION['name']); ?></h1>
                <p class="subtitle">Here's your quiz activity at a glance.</p>
            </div>
            <a href="studentPages/quizzes.php" class="btn">Browse quizzes</a>
        </div>

        <div class="stat-grid">
            <div class="stat-card is-navy">
                <span class="stat-icon"><?php echo icon('quiz'); ?></span>
                <div class="stat-value"><?php echo $published_total; ?></div>
                <div class="stat-label">Quizzes available</div>
            </div>
            <div class="stat-card is-success">
                <span class="stat-icon"><?php echo icon('check'); ?></span>
                <div class="stat-value"><?php echo $quizzes_completed; ?></div>
                <div class="stat-label">Quizzes completed</div>
            </div>
            <div class="stat-card">
                <span class="stat-icon"><?php echo icon('chart'); ?></span>
                <div class="stat-value"><?php echo $avg_pct !== null ? $avg_pct . '%' : '-'; ?></div>
                <div class="stat-label">Average score</div>
            </div>
            <div class="stat-card <?php echo $pending_review > 0 ? 'is-warning' : ''; ?>">
                <span class="stat-icon"><?php echo icon('pending'); ?></span>
                <div class="stat-value"><?php echo $pending_review; ?></div>
                <div class="stat-label">Awaiting review</div>
            </div>
        </div>

        <?php if ($overdue_count): ?>
            <div class="alert alert-error"><?php echo icon('alert'); ?><span>You have <?php echo $overdue_count; ?> overdue quiz<?php echo $overdue_count === 1 ? '' : 'zes'; ?>. <a class="link" href="studentPages/quizzes.php">See your quizzes &rarr;</a></span></div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">
                <h2>Recent scores</h2>
                <a class="link" href="studentPages/progress.php">My progress &rarr;</a>
            </div>
            <?php echo svg_line_chart(array_map(fn($p) => ['label' => date('M j', strtotime($p['date'])), 'value' => $p['pct'], 'title' => $p['title'] . ': ' . $p['pct'] . '%'], $trend), 'Your last scores'); ?>
            <?php if ($focus): ?>
                <p class="helptext">Topic to work on: <strong><?php echo e($focus['name']); ?></strong> (<?php echo e((string) round($focus['pct'])); ?>%). <a class="link" href="studentPages/practice.php">Practise &rarr;</a></p>
            <?php endif; ?>
        </div>

        <div class="card">
            <div class="card-header">
                <h2>Upcoming deadlines</h2>
                <a class="link" href="studentPages/quizzes.php">All quizzes &rarr;</a>
            </div>
            <?php if (empty($upcoming)): ?>
                <div class="empty-state"><?php echo $assigned ? 'Nothing due right now. Well done!' : 'No quizzes are assigned to you yet. Your teacher will add you to a class and assign quizzes.'; ?></div>
            <?php else: ?>
                <div class="card-list deadline-list">
                    <?php foreach ($upcoming as $q): ?>
                        <div class="item-row">
                            <div>
                                <div class="item-title"><?php echo e($q['title']); ?></div>
                                <div class="item-meta"><?php echo icon('clock'); ?> <?php echo e($q['avail']['label']); ?>
                                    &middot; <?php echo $q['time_limit_minutes'] ? (int) $q['time_limit_minutes'] . ' min limit' : 'No time limit'; ?></div>
                            </div>
                            <div class="item-actions">
                                <span class="badge <?php echo STATUS_BADGES[$q['status']]; ?>"><?php echo e(STATUS_LABELS[$q['status']]); ?></span>
                                <a class="btn btn-sm" href="studentPages/quizzes.php"><?php echo $q['summary']['in_progress'] ? 'Resume' : 'Open'; ?></a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>

</html>
