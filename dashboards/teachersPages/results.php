<?php
require_once __DIR__ . '/../../includes/bootstrap.php';

require_role('teacher', '../../auth/login.php');

$teacher_email = current_user_email();
$quiz_id = isset($_GET['quiz_id']) ? (int) $_GET['quiz_id'] : null;

$active = 'results';
$nav_base = '../';

if (!$quiz_id) {
    // Overview: all of this teacher's quizzes with aggregate stats
    $stmt = $conn->prepare(
        "SELECT q.id, q.title, q.status,
            COUNT(DISTINCT qa.student_email) AS student_count,
            COUNT(qa.id) AS attempt_count,
            SUM(CASE WHEN qa.needs_manual_grading = 1 THEN 1 ELSE 0 END) AS pending_count,
            AVG(qa.total_score / NULLIF(qa.max_score, 0)) AS avg_pct
         FROM quizzes q
         LEFT JOIN quiz_attempts qa ON qa.quiz_id = q.id AND qa.status = 'submitted' AND qa.is_practice = 0
         WHERE q.teacher_email = ?
         GROUP BY q.id, q.title, q.status
         ORDER BY q.created_at DESC"
    );
    $stmt->bind_param("s", $teacher_email);
    $stmt->execute();
    $quizzes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Individual attempts still awaiting manual evaluation, across all of
    // this teacher's quizzes, surfaced directly so "evaluate" is easy to find.
    $stmt = $conn->prepare(
        "SELECT qa.id AS attempt_id, qa.attempt_number, qa.submitted_at, q.title AS quiz_title, r.full_name, qa.student_email
         FROM quiz_attempts qa
         JOIN quizzes q ON q.id = qa.quiz_id
         LEFT JOIN reg_info r ON r.email = qa.student_email
         WHERE q.teacher_email = ? AND qa.needs_manual_grading = 1 AND qa.is_practice = 0
         ORDER BY qa.submitted_at ASC"
    );
    $stmt->bind_param("s", $teacher_email);
    $stmt->execute();
    $pending_attempts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <link rel="stylesheet" href="../navStyle.css?v=9">
        <link rel="stylesheet" href="../appStyle.css?v=9">
        <title>Results</title>
    </head>
    <body>
        <?php include APP_ROOT . '/dashboards/partials/nav_teacher.php'; ?>
        <div class="container" id="main">
            <div class="page-header">
                <div>
                    <h1>Results</h1>
                    <p class="subtitle">Select a quiz to see student scores and grade pending answers.</p>
                </div>
            </div>
            <?php if (!empty($pending_attempts)): ?>
                <div class="card">
                    <h2>Pending Evaluation (<?php echo count($pending_attempts); ?>)</h2>
                    <p class="subtitle" style="margin-top:-8px;">Short-answer questions with no answer key need you to score them by hand.</p>
                    <div class="card-list">
                        <?php foreach ($pending_attempts as $pa): ?>
                            <div class="item-row">
                                <div>
                                    <div class="item-title"><?php echo e($pa['quiz_title']); ?></div>
                                    <div class="item-meta"><?php echo e($pa['full_name'] ?? $pa['student_email']); ?> &middot; Attempt #<?php echo (int) $pa['attempt_number']; ?> &middot; Submitted <?php echo date('M j, Y g:i A', strtotime($pa['submitted_at'])); ?></div>
                                </div>
                                <a class="btn btn-sm" href="grade_attempt.php?attempt_id=<?php echo (int) $pa['attempt_id']; ?>">Evaluate</a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <div class="card">
                <?php if (empty($quizzes)): ?>
                    <div class="empty-state">You don't have any quizzes yet.</div>
                <?php else: ?>
                    <div class="card-list">
                        <?php foreach ($quizzes as $q): ?>
                            <div class="item-row">
                                <div>
                                    <div class="item-title"><?php echo e($q['title']); ?></div>
                                    <div class="item-meta">
                                        <?php echo (int) $q['student_count']; ?> student(s)
                                        &middot; <?php echo (int) $q['attempt_count']; ?> attempt(s)
                                        &middot; Avg: <?php echo $q['avg_pct'] !== null ? round($q['avg_pct'] * 100, 1) . '%' : '-'; ?>
                                    </div>
                                </div>
                                <div class="item-actions">
                                    <?php if ((int) $q['pending_count'] > 0): ?>
                                        <span class="badge badge-pending"><?php echo (int) $q['pending_count']; ?> pending</span>
                                    <?php endif; ?>
                                    <a class="btn btn-sm" href="results.php?quiz_id=<?php echo (int) $q['id']; ?>">View Results</a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// --- Per-quiz results -----------------------------------------------------
$stmt = $conn->prepare("SELECT * FROM quizzes WHERE id = ? AND teacher_email = ?");
$stmt->bind_param("is", $quiz_id, $teacher_email);
$stmt->execute();
$quiz = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$quiz) {
    header("Location: results.php");
    exit;
}

// Question analysis as CSV.
if (($_GET['export'] ?? '') === 'analysis') {
    $rows = [];
    foreach (question_analysis($conn, $quiz_id) as $i => $it) {
        $opts = implode(' | ', array_map(fn($o) => $o['text'] . ($o['correct'] ? ' (key)' : '') . ': ' . $o['count'], $it['options']));
        $rows[] = [$i + 1, $it['text'], question_type_label($it['type']), $it['answered'],
            $it['difficulty'] === null ? '' : round($it['difficulty'] * 100), $it['discrimination'] ?? '', $it['flag'], $opts];
    }
    send_csv('question-analysis-' . $quiz_id . '.csv', ['#', 'Question', 'Type', 'Answered', 'Average %', 'Discrimination', 'Flag', 'Options chosen'], $rows);
}

// Re-grade with the current answer key: preview first, then apply. Scores a
// teacher entered by hand are never changed; every change is audited.
$regrade_preview = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['regrade_preview', 'regrade_apply'], true)) {
    $apply = $_POST['action'] === 'regrade_apply';
    $result = regrade_quiz($conn, $quiz_id, $teacher_email, $apply);
    if ($apply) {
        flash('success', $result['changed'] . ' score(s) updated across ' . $result['attempts'] . ' submitted attempt(s).');
        redirect("results.php?quiz_id=$quiz_id");
    }
    $regrade_preview = $result;
}

// Per-student aggregate (average across attempts, per the grading policy)
$stmt = $conn->prepare(
    "SELECT student_email,
        COUNT(*) AS attempts_taken,
        SUM(CASE WHEN needs_manual_grading = 1 THEN 1 ELSE 0 END) AS pending_count,
        AVG(total_score / NULLIF(max_score, 0)) AS avg_pct,
        MAX(total_score / NULLIF(max_score, 0)) AS best_pct
     FROM quiz_attempts
     WHERE quiz_id = ? AND status = 'submitted' AND is_practice = 0
     GROUP BY student_email
     ORDER BY student_email ASC"
);
$stmt->bind_param("i", $quiz_id);
$stmt->execute();
$students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Attach names + individual attempts
foreach ($students as &$s) {
    $nstmt = $conn->prepare("SELECT full_name FROM reg_info WHERE email = ? LIMIT 1");
    $nstmt->bind_param("s", $s['student_email']);
    $nstmt->execute();
    $s['full_name'] = $nstmt->get_result()->fetch_assoc()['full_name'] ?? $s['student_email'];
    $nstmt->close();

    $astmt = $conn->prepare("SELECT * FROM quiz_attempts WHERE quiz_id = ? AND student_email = ? AND status = 'submitted' AND is_practice = 0 ORDER BY attempt_number ASC");
    $astmt->bind_param("is", $quiz_id, $s['student_email']);
    $astmt->execute();
    $s['attempts'] = $astmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $astmt->close();
}
unset($s);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../navStyle.css?v=9">
    <link rel="stylesheet" href="../appStyle.css?v=9">
    <title>Results - <?php echo e($quiz['title']); ?></title>
</head>
<body>
    <?php include APP_ROOT . '/dashboards/partials/nav_teacher.php'; ?>
    <div class="container" id="main">
        <div class="page-header">
            <div>
                <h1><?php echo e($quiz['title']); ?></h1>
                <p class="subtitle">Per-student results. Attempts average together when a quiz allows more than one.</p>
                <p class="print-only">Results report &middot; <?php echo e($_SESSION['name'] ?? ''); ?> &middot; Generated <?php echo e(date('M j, Y g:i A')); ?></p>
            </div>
            <div class="item-actions">
                <a href="export_results.php?quiz_id=<?php echo (int) $quiz_id; ?>" class="btn btn-outline"><?php echo icon('download'); ?> Results CSV</a>
                <a href="results.php?quiz_id=<?php echo (int) $quiz_id; ?>&amp;export=analysis" class="btn btn-outline"><?php echo icon('download'); ?> Analysis CSV</a>
                <button type="button" class="btn btn-outline" data-print><?php echo icon('print'); ?> Print / Save as PDF</button>
                <a href="results.php" class="btn btn-outline">&larr; All Quizzes</a>
            </div>
        </div>

        <?php
        // Summary across students, and how each question performed (item analysis).
        $avg_values = array_values(array_filter(array_column($students, 'avg_pct'), fn($v) => $v !== null));
        $total_submissions = array_sum(array_map(fn($s) => (int) $s['attempts_taken'], $students));
        $pending_total = array_sum(array_map(fn($s) => (int) $s['pending_count'], $students));
        $class_avg = $avg_values ? array_sum($avg_values) / count($avg_values) : null;

        $items = question_analysis($conn, $quiz_id);
        ?>

        <?php if (!empty($students)): ?>
        <div class="card">
            <div class="stat-grid">
                <div class="stat-card">
                    <div class="stat-value"><?php echo count($students); ?></div>
                    <div class="stat-label">Students attempted</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?php echo $total_submissions; ?></div>
                    <div class="stat-label">Submitted attempts</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?php echo $class_avg !== null ? round($class_avg * 100, 1) . '%' : '-'; ?></div>
                    <div class="stat-label">Class average</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?php echo $pending_total; ?></div>
                    <div class="stat-label">Answers to grade</div>
                </div>
            </div>
        </div>

        <div class="card" id="regrade">
            <div class="card-header">
                <h2>Re-grade with the current answer key</h2>
            </div>
            <p class="helptext">After correcting a question's answer key, points or grading rules, apply them to submitted attempts.
                Only automatically graded answers change; scores you entered by hand are kept. Every change is recorded.</p>
            <?php if ($regrade_preview !== null): ?>
                <?php if ($regrade_preview['changed'] === 0): ?>
                    <div class="alert alert-info">Nothing would change: every automatically graded answer already matches the current key.</div>
                <?php else: ?>
                    <div class="alert alert-warning"><?php echo icon('alert'); ?><span><?php echo (int) $regrade_preview['changed']; ?> score(s) in <?php echo count(array_unique(array_column($regrade_preview['details'], 'attempt_id'))); ?> attempt(s) would change.</span></div>
                    <div class="table-wrap"><table class="data-table" aria-label="Re-grade preview">
                        <thead><tr><th>Student</th><th>Attempt</th><th>Question</th><th>Now</th><th>After</th></tr></thead>
                        <tbody>
                            <?php foreach ($regrade_preview['details'] as $d): ?>
                                <tr>
                                    <td><?php echo e($d['student']); ?></td>
                                    <td>#<?php echo (int) $d['attempt_id']; ?></td>
                                    <td><?php echo (int) $d['qid']; ?></td>
                                    <td><?php echo $d['old'] === null ? 'Pending' : fmt_score($d['old']); ?></td>
                                    <td><?php echo $d['new'] === null ? 'Pending' : fmt_score($d['new']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table></div>
                    <form method="POST" class="item-actions is-spaced" data-confirm="Update these scores? Students will see the new results." data-danger><?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="regrade_apply">
                        <button type="submit" class="btn">Apply re-grade</button>
                    </form>
                <?php endif; ?>
            <?php else: ?>
                <form method="POST"><?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="regrade_preview">
                    <button type="submit" class="btn btn-outline">Preview changes</button>
                </form>
            <?php endif; ?>
        </div>

        <?php if (!empty($items)): ?>
        <div class="card">
            <h2>Question analysis</h2>
            <p class="helptext"><strong>Average</strong>: share of points earned (low = hard). <strong>Discrimination</strong>: how much better the top 27% of attempts did than the bottom 27%;
                above 0.3 separates stronger and weaker students well, near 0 or negative suggests confusing wording or a wrong key.</p>
            <div class="table-wrap">
                <table class="data-table" aria-label="Question analysis">
                    <thead><tr><th>#</th><th>Question</th><th>Type</th><th>Answered</th><th>Average</th><th>Discrimination</th></tr></thead>
                    <tbody>
                        <?php foreach ($items as $i => $it):
                            $pct = $it['difficulty'] !== null ? round($it['difficulty'] * 100) : null; ?>
                            <tr>
                                <td><?php echo $i + 1; ?></td>
                                <td class="cell-wrap">
                                    <?php echo e(mb_strimwidth($it['text'], 0, 110, '…')); ?><?php if ($it['removed']): ?> <span class="tag">removed from quiz</span><?php endif; ?>
                                    <?php if ($it['flag'] !== ''): ?><div><span class="badge badge-pending"><?php echo e($it['flag']); ?></span></div><?php endif; ?>
                                    <?php if ($it['options']):
                                        $total = max(1, (int) $it['answered']); ?>
                                        <details>
                                            <summary class="cell-muted">Options chosen</summary>
                                            <ul class="distractors">
                                                <?php foreach ($it['options'] as $o): $share = round($o['count'] / $total * 100); ?>
                                                    <li class="<?php echo $o['correct'] ? 'is-key' : ''; ?>">
                                                        <span><?php echo e($o['text']); ?><?php echo $o['correct'] ? ' ✓' : ''; ?></span>
                                                        <span class="bar-track"><span class="bar-fill<?php echo $o['correct'] ? '' : ' is-mid'; ?>" style="width:<?php echo $share; ?>%"></span></span>
                                                        <span><?php echo $share; ?>%</span>
                                                    </li>
                                                <?php endforeach; ?>
                                                <?php if ($it['blank']): ?><li><span class="cell-muted">No answer</span><span></span><span><?php echo round($it['blank'] / $total * 100); ?>%</span></li><?php endif; ?>
                                            </ul>
                                        </details>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo e(question_type_label($it['type'])); ?></td>
                                <td><?php echo (int) $it['answered']; ?></td>
                                <td data-sort="<?php echo $pct ?? -1; ?>"><span class="score-pill <?php echo score_band($pct); ?>"><?php echo $pct !== null ? $pct . '%' : '-'; ?></span></td>
                                <td data-sort="<?php echo $it['discrimination'] ?? -9; ?>"><?php echo $it['discrimination'] === null ? '<span class="cell-muted">-</span>' : e(number_format($it['discrimination'], 2)); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
        <?php endif; ?>

        <div class="card">
            <?php if (empty($students)): ?>
                <div class="empty-state">No submissions yet.</div>
            <?php else: ?>
                <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr><th>Student</th><th>Attempts</th><th>Average</th><th>Best</th><th>Status</th><th></th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($students as $s): ?>
                            <tr>
                                <td><?php echo e($s['full_name']); ?><br><span class="item-meta"><?php echo e($s['student_email']); ?></span></td>
                                <td><?php echo (int) $s['attempts_taken']; ?></td>
                                <td><?php echo $s['avg_pct'] !== null ? round($s['avg_pct'] * 100, 1) . '%' : '-'; ?></td>
                                <td><?php echo $s['best_pct'] !== null ? round($s['best_pct'] * 100, 1) . '%' : '-'; ?></td>
                                <td>
                                    <?php if ((int) $s['pending_count'] > 0): ?>
                                        <span class="badge badge-pending"><?php echo (int) $s['pending_count']; ?> pending</span>
                                    <?php else: ?>
                                        <span class="badge badge-graded">Graded</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php foreach ($s['attempts'] as $a): ?>
                                        <a class="btn btn-sm <?php echo $a['needs_manual_grading'] ? '' : 'btn-outline'; ?>" style="margin: 2px;" href="grade_attempt.php?attempt_id=<?php echo (int) $a['id']; ?>">
                                            <?php echo $a['needs_manual_grading'] ? 'Evaluate #' . (int) $a['attempt_number'] : 'View #' . (int) $a['attempt_number']; ?>
                                        </a><?php if (attempt_is_late($a)): ?><span class="badge badge-pending" title="Submitted after the deadline">Late</span><?php endif; ?>
                                    <?php endforeach; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
