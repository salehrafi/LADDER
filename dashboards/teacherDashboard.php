<?php
require_once __DIR__ . '/../includes/bootstrap.php';

require_role('teacher', '../auth/login.php');

$teacher_email = current_user_email();

$quiz_counts = ['total' => 0, 'published' => 0, 'draft' => 0, 'closed' => 0];
$stmt = $conn->prepare("SELECT status, COUNT(*) AS c FROM quizzes WHERE teacher_email = ? GROUP BY status");
$stmt->bind_param("s", $teacher_email);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $quiz_counts[$row['status']] = (int) $row['c'];
    $quiz_counts['total'] += (int) $row['c'];
}
$stmt->close();

$stmt = $conn->prepare(
    "SELECT COUNT(*) AS c FROM quiz_attempts qa
     JOIN quizzes q ON q.id = qa.quiz_id
     WHERE q.teacher_email = ? AND qa.status = 'submitted' AND qa.is_practice = 0"
);
$stmt->bind_param("s", $teacher_email);
$stmt->execute();
$total_attempts = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();

$stmt = $conn->prepare(
    "SELECT COUNT(*) AS c FROM quiz_attempts qa
     JOIN quizzes q ON q.id = qa.quiz_id
     WHERE q.teacher_email = ? AND qa.needs_manual_grading = 1 AND qa.is_practice = 0"
);
$stmt->bind_param("s", $teacher_email);
$stmt->execute();
$pending_grading = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();

$stmt = $conn->prepare(
    "SELECT COUNT(DISTINCT ss.student_email) AS c FROM section_students ss
     JOIN sections s ON s.id = ss.section_id WHERE s.teacher_email = ?"
);
$stmt->bind_param("s", $teacher_email);
$stmt->execute();
$student_count = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();

$stmt = $conn->prepare(
    "SELECT AVG(qa.total_score / NULLIF(qa.max_score, 0)) AS p FROM quiz_attempts qa
     JOIN quizzes q ON q.id = qa.quiz_id
     WHERE q.teacher_email = ? AND qa.status = 'submitted' AND qa.is_practice = 0 AND qa.needs_manual_grading = 0"
);
$stmt->bind_param("s", $teacher_email);
$stmt->execute();
$avg = $stmt->get_result()->fetch_assoc()['p'];
$avg_pct = $avg !== null ? round((float) $avg * 100, 1) : null;
$stmt->close();

// Recent quizzes
$stmt = $conn->prepare("SELECT id, title, status, updated_at FROM quizzes WHERE teacher_email = ? ORDER BY updated_at DESC LIMIT 5");
$stmt->bind_param("s", $teacher_email);
$stmt->execute();
$recent_quizzes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$active = 'home';
$nav_base = '';
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="navStyle.css?v=10">
    <link rel="stylesheet" href="appStyle.css?v=10">
    <title>Teachers Dashboard</title>
</head>

<body>
    <?php include APP_ROOT . '/dashboards/partials/nav_teacher.php'; ?>

    <div class="container" id="main">
        <div class="page-header">
            <div>
                <h1>Welcome, <?php echo e($_SESSION['name']); ?></h1>
                <p class="subtitle">Here's what's happening with your quizzes and classes.</p>
            </div>
            <div class="item-actions">
                <a href="teachersPages/classes.php" class="btn btn-outline">Manage classes</a>
                <a href="teachersPages/setQuiz.php" class="btn">+ New quiz</a>
            </div>
        </div>

        <div class="stat-grid">
            <div class="stat-card is-navy">
                <span class="stat-icon"><?php echo icon('quiz'); ?></span>
                <div class="stat-value"><?php echo $quiz_counts['total']; ?></div>
                <div class="stat-label">Quizzes (<?php echo $quiz_counts['published']; ?> published)</div>
            </div>
            <div class="stat-card">
                <span class="stat-icon"><?php echo icon('users'); ?></span>
                <div class="stat-value"><?php echo $student_count; ?></div>
                <div class="stat-label">Students in my classes</div>
            </div>
            <div class="stat-card <?php echo $pending_grading > 0 ? 'is-warning' : ''; ?>">
                <span class="stat-icon"><?php echo icon('pending'); ?></span>
                <div class="stat-value"><?php echo $pending_grading; ?></div>
                <div class="stat-label">Pending evaluations</div>
            </div>
            <div class="stat-card is-success">
                <span class="stat-icon"><?php echo icon('check'); ?></span>
                <div class="stat-value"><?php echo $total_attempts; ?></div>
                <div class="stat-label">Completed attempts</div>
            </div>
            <div class="stat-card">
                <span class="stat-icon"><?php echo icon('chart'); ?></span>
                <div class="stat-value"><?php echo $avg_pct !== null ? $avg_pct . '%' : '-'; ?></div>
                <div class="stat-label">Average score</div>
            </div>
        </div>

        <?php if ($pending_grading > 0): ?>
            <div class="alert alert-warning">
                <?php echo icon('alert'); ?>
                <span>You have <?php echo $pending_grading; ?> short-answer attempt<?php echo $pending_grading === 1 ? '' : 's'; ?> waiting for you to evaluate.
                <a class="link" href="teachersPages/results.php">Evaluate now &rarr;</a></span>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">
                <h2>Recent quizzes</h2>
                <a class="link" href="teachersPages/setQuiz.php">All quizzes &rarr;</a>
            </div>
            <?php if (empty($recent_quizzes)): ?>
                <div class="empty-state">You haven't created any quizzes yet. <a class="link" href="teachersPages/setQuiz.php">Create your first quiz &rarr;</a></div>
            <?php else: ?>
                <div class="card-list">
                    <?php foreach ($recent_quizzes as $q): ?>
                        <div class="item-row">
                            <div>
                                <div class="item-title"><?php echo e($q['title']); ?></div>
                                <div class="item-meta">Updated <?php echo date('M j, Y g:i A', strtotime($q['updated_at'])); ?></div>
                            </div>
                            <div class="item-actions">
                                <span class="badge badge-<?php echo e($q['status']); ?>"><?php echo ucfirst(e($q['status'])); ?></span>
                                <a class="btn btn-outline btn-sm" href="teachersPages/quiz_questions.php?quiz_id=<?php echo (int) $q['id']; ?>">Questions</a>
                                <a class="btn btn-outline btn-sm" href="teachersPages/results.php?quiz_id=<?php echo (int) $q['id']; ?>">Results</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>

</html>
