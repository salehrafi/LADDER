<?php
/**
 * Class performance analytics for the teacher's quizzes: score distribution,
 * per-quiz, per-section and per-topic averages, and a per-student table.
 * Filter by section and quiz; export as CSV or print / save as PDF.
 */
require_once __DIR__ . '/../../includes/bootstrap.php';

require_role('teacher', '../../auth/login.php');

$teacher_email = current_user_email();
$sections = teacher_sections($conn, $teacher_email);
$stmt = $conn->prepare("SELECT id, title FROM quizzes WHERE teacher_email = ? AND mode = 'official' ORDER BY title");
$stmt->bind_param("s", $teacher_email);
$stmt->execute();
$quizzes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Filters accept only the teacher's own sections and quizzes.
$section_id = (int) ($_GET['section'] ?? 0);
$section_id = in_array($section_id, array_map('intval', array_column($sections, 'id')), true) ? $section_id : 0;
$quiz_id = (int) ($_GET['quiz'] ?? 0);
$quiz_id = in_array($quiz_id, array_map('intval', array_column($quizzes, 'id')), true) ? $quiz_id : 0;

$data = class_analytics($conn, $teacher_email, $section_id, $quiz_id);
$sum = $data['summary'];

if (($_GET['export'] ?? '') === 'students') {
    send_csv('class-analytics-' . date('Y-m-d') . '.csv', ['Student', 'Email', 'Quizzes', 'Attempts', 'Average %', 'Last submitted'],
        array_map(fn($s) => [$s['name'], $s['email'], $s['quizzes'], $s['attempts'], $s['avg'], $s['last']], $data['students']));
}

$filter_label = trim(
    ($section_id ? 'Section: ' . (array_column($sections, 'name', 'id')[$section_id] ?? '') : 'All sections') . ' · '
    . ($quiz_id ? 'Quiz: ' . (array_column($quizzes, 'title', 'id')[$quiz_id] ?? '') : 'All quizzes')
);
$query = http_build_query(array_filter(['section' => $section_id ?: null, 'quiz' => $quiz_id ?: null]));

$active = 'analytics';
$nav_base = '../';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../navStyle.css?v=9">
    <link rel="stylesheet" href="../appStyle.css?v=9">
    <title>Analytics · LADDER</title>
</head>

<body>
    <?php include APP_ROOT . '/dashboards/partials/nav_teacher.php'; ?>

    <div class="container" id="main">
        <div class="report-header print-only">
            <h1>Class performance report</h1>
            <div><?php echo e($_SESSION['name'] ?? ''); ?> &middot; <?php echo e($filter_label); ?> &middot; Generated <?php echo e(date('M j, Y g:i A')); ?></div>
        </div>

        <div class="page-header">
            <div>
                <h1>Class analytics</h1>
                <p class="subtitle">Graded, official attempts on your quizzes. <?php echo e($filter_label); ?>.</p>
            </div>
            <div class="item-actions">
                <a class="btn btn-outline" href="analytics.php?<?php echo e(($query ? $query . '&' : '') . 'export=students'); ?>"><?php echo icon('download'); ?> Export CSV</a>
                <button type="button" class="btn btn-outline" data-print><?php echo icon('print'); ?> Print / Save as PDF</button>
            </div>
        </div>

        <form method="GET" class="filter-bar no-print" action="analytics.php">
            <select name="section" aria-label="Section">
                <option value="0">All sections</option>
                <?php foreach ($sections as $s): ?><option value="<?php echo (int) $s['id']; ?>" <?php echo $section_id === (int) $s['id'] ? 'selected' : ''; ?>><?php echo e($s['name']); ?></option><?php endforeach; ?>
            </select>
            <select name="quiz" aria-label="Quiz">
                <option value="0">All quizzes</option>
                <?php foreach ($quizzes as $q): ?><option value="<?php echo (int) $q['id']; ?>" <?php echo $quiz_id === (int) $q['id'] ? 'selected' : ''; ?>><?php echo e($q['title']); ?></option><?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-outline">Apply</button>
            <?php if ($query): ?><a class="btn btn-muted" href="analytics.php">Clear</a><?php endif; ?>
        </form>

        <div class="stat-grid">
            <div class="stat-card"><span class="stat-icon"><?php echo icon('chart'); ?></span><div class="stat-value"><?php echo $sum['average'] !== null ? $sum['average'] . '%' : '-'; ?></div><div class="stat-label">Average score</div></div>
            <div class="stat-card is-navy"><span class="stat-icon"><?php echo icon('results'); ?></span><div class="stat-value"><?php echo $sum['median'] !== null ? $sum['median'] . '%' : '-'; ?></div><div class="stat-label">Median score</div></div>
            <div class="stat-card is-success"><span class="stat-icon"><?php echo icon('check'); ?></span><div class="stat-value"><?php echo $sum['pass_rate'] !== null ? $sum['pass_rate'] . '%' : '-'; ?></div><div class="stat-label">Attempts at 50% or more</div></div>
            <div class="stat-card"><span class="stat-icon"><?php echo icon('users'); ?></span><div class="stat-value"><?php echo (int) $sum['students']; ?></div><div class="stat-label">Students (<?php echo (int) $sum['attempts']; ?> attempts)</div></div>
        </div>

        <?php if (!$sum['attempts']): ?>
            <div class="card"><div class="empty-state">No graded attempts match these filters yet.</div></div>
        <?php else: ?>
            <div class="card">
                <h2>Score distribution</h2>
                <?php echo svg_bar_chart($data['distribution'], 'Number of attempts in each 10-point score band'); ?>
            </div>

            <div class="analytics-grid">
                <div class="card">
                    <h2>By quiz</h2>
                    <?php echo bar_list(array_map(fn($q) => ['name' => $q['title'], 'pct' => $q['avg'], 'meta' => $q['students'] . ' students'], $data['quizzes']), 'Average by quiz'); ?>
                </div>
                <div class="card">
                    <h2>By section</h2>
                    <?php echo bar_list(array_map(fn($s) => ['name' => $s['name'], 'pct' => $s['avg'], 'meta' => $s['attempts'] . ' attempts'], $data['sections']), 'Average by section'); ?>
                </div>
            </div>

            <div class="card">
                <h2>By topic <span class="cell-muted">(weakest first)</span></h2>
                <?php if (!$data['topics']): ?>
                    <div class="empty-state">Give questions a topic (Question Bank → Subjects &amp; topics) to see topic performance.</div>
                <?php else: ?>
                    <?php echo bar_list(array_map(fn($t) => ['name' => $t['name'], 'pct' => $t['pct'], 'meta' => $t['answers'] . ' answers'], $data['topics']), 'Average by topic'); ?>
                <?php endif; ?>
            </div>

            <div class="card">
                <h2>Students</h2>
                <div class="table-wrap">
                    <table class="data-table" aria-label="Student performance">
                        <thead><tr><th>Student</th><th>Quizzes</th><th>Attempts</th><th>Average</th><th>Last submitted</th></tr></thead>
                        <tbody>
                            <?php foreach ($data['students'] as $s): ?>
                                <tr>
                                    <td><a class="link" href="student_profile.php?email=<?php echo e(urlencode($s['email'])); ?>"><?php echo e($s['name']); ?></a><div class="cell-muted"><?php echo e($s['email']); ?></div></td>
                                    <td><?php echo (int) $s['quizzes']; ?></td>
                                    <td><?php echo (int) $s['attempts']; ?></td>
                                    <td data-sort="<?php echo e((string) $s['avg']); ?>"><span class="score-pill <?php echo score_band($s['avg']); ?>"><?php echo e((string) $s['avg']); ?>%</span></td>
                                    <td class="cell-muted"><?php echo e(date('M j, Y', strtotime($s['last']))); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>
</body>

</html>
