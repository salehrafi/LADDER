<?php
/**
 * My progress: score trend, subject-wise performance, strongest and weakest
 * topics, and completion. Uses only official attempts whose results have
 * been released. Printable as a progress report (Save as PDF).
 */
require_once __DIR__ . '/../../includes/bootstrap.php';

require_role('student', '../../auth/login.php');

$email = current_user_email();
$trend = student_score_trend($conn, $email, 30);
$subjects = student_group_performance($conn, $email, 'subject');
$topics = student_strengths($conn, $email);
$completion = student_completion($conn, $email);
$avg = $trend ? round(array_sum(array_column($trend, 'pct')) / count($trend), 1) : null;
$recent = array_slice($trend, -3);
$earlier = array_slice($trend, 0, max(0, count($trend) - 3));
$change = ($recent && $earlier)
    ? round(array_sum(array_column($recent, 'pct')) / count($recent) - array_sum(array_column($earlier, 'pct')) / count($earlier), 1)
    : null;

$active = 'progress';
$nav_base = '../';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../navStyle.css?v=9">
    <link rel="stylesheet" href="../appStyle.css?v=10">
    <title>My Progress · LADDER</title>
</head>

<body>
    <?php include APP_ROOT . '/dashboards/partials/nav_student.php'; ?>

    <div class="container" id="main">
        <div class="report-header print-only">
            <h1>Progress report: <?php echo e($_SESSION['name'] ?? ''); ?></h1>
            <div><?php echo e($email); ?> &middot; Generated <?php echo e(date('M j, Y g:i A')); ?></div>
        </div>

        <div class="page-header">
            <div>
                <h1>My Progress</h1>
                <p class="subtitle">Based on your graded quizzes whose results your teachers have released. Practice is not included.</p>
            </div>
            <button type="button" class="btn btn-outline no-print" data-print><?php echo icon('print'); ?> Print / Save as PDF</button>
        </div>

        <div class="stat-grid">
            <div class="stat-card is-success"><span class="stat-icon"><?php echo icon('check'); ?></span>
                <div class="stat-value"><?php echo $completion['rate'] !== null ? $completion['rate'] . '%' : '-'; ?></div>
                <div class="stat-label">Completion (<?php echo $completion['completed'] + $completion['completed_late']; ?> of <?php echo $completion['total'] - $completion['upcoming']; ?>)</div></div>
            <div class="stat-card"><span class="stat-icon"><?php echo icon('chart'); ?></span>
                <div class="stat-value"><?php echo $avg !== null ? $avg . '%' : '-'; ?></div>
                <div class="stat-label">Average score</div></div>
            <div class="stat-card is-navy"><span class="stat-icon"><?php echo icon('results'); ?></span>
                <div class="stat-value"><?php echo $change === null ? '-' : ($change >= 0 ? '+' : '') . $change; ?></div>
                <div class="stat-label">Recent trend (last 3 vs earlier, points)</div></div>
            <div class="stat-card <?php echo $completion['overdue'] ? 'is-warning' : ''; ?>"><span class="stat-icon"><?php echo icon('alert'); ?></span>
                <div class="stat-value"><?php echo $completion['overdue']; ?></div>
                <div class="stat-label">Overdue quizzes</div></div>
        </div>

        <div class="card">
            <h2>Score trend</h2>
            <?php echo svg_line_chart(array_map(fn($p) => ['label' => date('M j', strtotime($p['date'])), 'value' => $p['pct'], 'title' => $p['title'] . ' (' . date('M j', strtotime($p['date'])) . '): ' . $p['pct'] . '%'], $trend),
                'Your scores over time' . ($avg !== null ? ", averaging $avg%" : '')); ?>
        </div>

        <div class="analytics-grid">
            <div class="card">
                <h2>By subject</h2>
                <?php echo bar_list(array_map(fn($s) => ['name' => $s['name'], 'pct' => $s['pct'], 'meta' => $s['answers'] . ' answers'], $subjects), 'Score by subject'); ?>
            </div>
            <div class="card">
                <h2>Completion</h2>
                <ul class="topic-list">
                    <?php foreach (['completed', 'completed_late', 'in_progress', 'not_started', 'overdue', 'upcoming'] as $k): ?>
                        <li><span><span class="badge <?php echo STATUS_BADGES[$k]; ?>"><?php echo e(STATUS_LABELS[$k]); ?></span></span><strong><?php echo (int) $completion[$k]; ?></strong></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>

        <div class="analytics-grid">
            <div class="card">
                <h2>Strongest topics</h2>
                <?php if (!$topics['strong']): ?>
                    <div class="empty-state">Not enough graded answers per topic yet.</div>
                <?php else: ?>
                    <ul class="topic-list is-strong">
                        <?php foreach ($topics['strong'] as $t): ?><li><span><?php echo e($t['name']); ?> <span class="cell-muted">(<?php echo (int) $t['answers']; ?> answers)</span></span><strong><?php echo e((string) round($t['pct'])); ?>%</strong></li><?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
            <div class="card">
                <h2>Topics to work on</h2>
                <?php if (!$topics['weak']): ?>
                    <div class="empty-state">No weak topics right now. Keep it up!</div>
                <?php else: ?>
                    <ul class="topic-list is-weak">
                        <?php foreach ($topics['weak'] as $t): ?><li><span><?php echo e($t['name']); ?> <span class="cell-muted">(<?php echo (int) $t['answers']; ?> answers)</span></span><strong><?php echo e((string) round($t['pct'])); ?>%</strong></li><?php endforeach; ?>
                    </ul>
                    <p class="helptext no-print">Try the <a class="link" href="practice.php">practice quizzes</a> and review your <a class="link" href="bookmarks.php">bookmarked questions</a>.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>

</html>
