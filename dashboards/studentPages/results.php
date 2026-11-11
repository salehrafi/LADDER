<?php
require_once __DIR__ . '/../../includes/bootstrap.php';

require_role('student', '../../auth/login.php');

$student_email = current_user_email();
$filter_quiz_id = isset($_GET['quiz_id']) ? (int) $_GET['quiz_id'] : null;

// All quizzes the student has ever attempted, with per-quiz aggregate stats
// Scores are hidden until the teacher's release rule allows them (see results_released()).
$sql = "SELECT q.id AS quiz_id, q.title, q.status, q.end_time, q.results_release, q.results_released_at,
        COUNT(qa.id) AS attempts_taken,
        SUM(CASE WHEN qa.needs_manual_grading = 1 THEN 1 ELSE 0 END) AS pending_count,
        AVG(qa.total_score / NULLIF(qa.max_score, 0)) AS avg_pct,
        MAX(qa.total_score / NULLIF(qa.max_score, 0)) AS best_pct
        FROM quiz_attempts qa
        JOIN quizzes q ON q.id = qa.quiz_id
        WHERE qa.student_email = ? AND qa.status = 'submitted' AND qa.is_practice = 0";
$params = [$student_email];
$types = "s";
if ($filter_quiz_id) {
    $sql .= " AND q.id = ?";
    $params[] = $filter_quiz_id;
    $types .= "i";
}
$sql .= " GROUP BY q.id, q.title, q.status, q.end_time, q.results_release, q.results_released_at ORDER BY MAX(qa.submitted_at) DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$quiz_summaries = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Individual attempts per quiz
foreach ($quiz_summaries as &$qs) {
    $qs['released'] = results_released($qs);
    $stmt = $conn->prepare("SELECT * FROM quiz_attempts WHERE quiz_id = ? AND student_email = ? AND status = 'submitted' AND is_practice = 0 ORDER BY attempt_number ASC");
    $stmt->bind_param("is", $qs['quiz_id'], $student_email);
    $stmt->execute();
    $qs['attempts'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}
unset($qs);

$active = 'results';
$nav_base = '../';
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../navStyle.css?v=9">
    <link rel="stylesheet" href="../appStyle.css?v=10">
    <title>My Results</title>
</head>

<body>
    <?php include APP_ROOT . '/dashboards/partials/nav_student.php'; ?>

    <div class="container" id="main">
        <div class="page-header">
            <div>
                <h1>My Results</h1>
                <p class="subtitle">When a quiz allows multiple attempts, your grade is the average across all submitted attempts.</p>
            </div>
        </div>

        <?php if (empty($quiz_summaries)): ?>
            <div class="card"><div class="empty-state">You haven't completed any quizzes yet.</div></div>
        <?php else: ?>
            <?php foreach ($quiz_summaries as $qs): ?>
                <div class="card">
                    <div class="page-header" style="margin-bottom:10px;">
                        <div>
                            <h2 style="margin:0;"><?php echo e($qs['title']); ?></h2>
                            <p class="subtitle"><?php echo (int) $qs['attempts_taken']; ?> attempt(s) submitted<?php echo ($qs['released'] && $qs['pending_count'] > 0) ? ' · ' . (int) $qs['pending_count'] . ' pending review' : ''; ?></p>
                            <?php if (!$qs['released']): ?><p class="subtitle"><?php echo e(results_pending_note($qs)); ?></p><?php endif; ?>
                        </div>
                        <?php if ($qs['released']): ?>
                        <div class="stat-grid" style="grid-template-columns: repeat(2, auto); margin-bottom:0;">
                            <div class="stat-card">
                                <div class="stat-value"><?php echo $qs['avg_pct'] !== null ? round($qs['avg_pct'] * 100, 1) . '%' : '-'; ?></div>
                                <div class="stat-label">Average</div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-value"><?php echo $qs['best_pct'] !== null ? round($qs['best_pct'] * 100, 1) . '%' : '-'; ?></div>
                                <div class="stat-label">Best</div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="table-wrap"><table class="data-table">
                        <thead>
                            <tr><th>Attempt</th><th>Submitted</th><th>Score</th><th>Status</th><th></th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($qs['attempts'] as $a): ?>
                                <tr>
                                    <td>#<?php echo (int) $a['attempt_number']; ?><?php if (attempt_is_late($a)): ?> <span class="badge badge-pending">Late</span><?php endif; ?></td>
                                    <td><?php echo date('M j, Y g:i A', strtotime($a['submitted_at'])); ?></td>
                                    <td><?php echo $qs['released'] ? fmt_score($a['total_score']) . ' / ' . fmt_score($a['max_score']) : '-'; ?></td>
                                    <td>
                                        <?php if (!$qs['released']): ?>
                                            <span class="badge badge-pending">Results not released</span>
                                        <?php elseif ($a['needs_manual_grading']): ?>
                                            <span class="badge badge-pending">Pending</span>
                                        <?php else: ?>
                                            <span class="badge badge-graded">Graded</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><a class="link" href="view_attempt.php?attempt_id=<?php echo (int) $a['id']; ?>">Review</a></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table></div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</body>

</html>
