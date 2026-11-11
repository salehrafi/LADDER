<?php
require_once __DIR__ . '/../../includes/bootstrap.php';

require_role('teacher', '../../auth/login.php');

$teacher_email = current_user_email();
$attempt_id = (int) ($_GET['attempt_id'] ?? $_POST['attempt_id'] ?? 0);

// Load the attempt, but only if it belongs to one of this teacher's quizzes.
$stmt = $conn->prepare(
    "SELECT qa.*, q.title AS quiz_title, q.id AS quiz_id FROM quiz_attempts qa
     JOIN quizzes q ON q.id = qa.quiz_id
     WHERE qa.id = ? AND q.teacher_email = ?"
);
$stmt->bind_param("is", $attempt_id, $teacher_email);
$stmt->execute();
$attempt = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$attempt) {
    header("Location: results.php");
    exit;
}

$success = "";

// --- Save grades and feedback -------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_grades') {
    $scores = is_array($_POST['score'] ?? null) ? $_POST['score'] : [];
    $feedback = is_array($_POST['feedback'] ?? null) ? $_POST['feedback'] : [];

    // One transaction; scores are clamped to the points the question had when
    // the attempt was taken, and only this attempt's answers can change.
    $still_pending = save_teacher_grades($conn, $attempt, $teacher_email, $scores, $feedback);
    flash('success', $still_pending ? "Grades saved. Some answers still need a score." : "Grades saved. This attempt is fully graded.");
    redirect("grade_attempt.php?attempt_id=" . $attempt_id);
}

foreach (take_flashes() as $f) {
    $success = $f['message'];
}

// Answers with the questions exactly as this attempt was taken (snapshot).
$rows = attempt_review_rows($conn, $attempt);
$backfilled = (bool) array_filter(array_column($rows, 'backfilled'));

$student_stmt = $conn->prepare("SELECT full_name FROM reg_info WHERE email = ? LIMIT 1");
$student_stmt->bind_param("s", $attempt['student_email']);
$student_stmt->execute();
$student_name = $student_stmt->get_result()->fetch_assoc()['full_name'] ?? $attempt['student_email'];
$student_stmt->close();

// The next submitted attempt in this quiz that still needs grading, so the
// teacher can work through the queue without going back to the list.
$stmt = $conn->prepare(
    "SELECT id FROM quiz_attempts
     WHERE quiz_id = ? AND needs_manual_grading = 1 AND status = 'submitted' AND is_practice = 0 AND id <> ?
     ORDER BY submitted_at ASC LIMIT 1"
);
$stmt->bind_param("ii", $attempt['quiz_id'], $attempt_id);
$stmt->execute();
$next_pending = $stmt->get_result()->fetch_assoc();
$stmt->close();

$max_total = array_sum(array_column($rows, 'question_points'));

$active = 'results';
$nav_base = '../';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../navStyle.css?v=10">
    <link rel="stylesheet" href="../appStyle.css?v=10">
    <title>Grade Attempt</title>
</head>
<body>
    <?php include APP_ROOT . '/dashboards/partials/nav_teacher.php'; ?>
    <div class="container" id="main">
        <div class="page-header">
            <div>
                <h1><?php echo e($attempt['quiz_title']); ?></h1>
                <p class="subtitle">
                    <?php echo e($student_name); ?> &middot; Attempt #<?php echo (int) $attempt['attempt_number']; ?>
                    &middot; Submitted <?php echo $attempt['submitted_at'] ? date('M j, Y g:i A', strtotime($attempt['submitted_at'])) : '-'; ?>
                </p>
                <?php if (!empty($attempt['submit_reason']) && $attempt['submit_reason'] !== 'manual'): ?>
                    <span class="badge <?php echo $attempt['submit_reason'] === 'left_page' ? 'badge-closed' : 'badge-pending'; ?> reason-badge"><?php echo e(submit_reason_label($attempt['submit_reason'])); ?></span>
                <?php endif; ?>
            </div>
            <div class="item-actions">
                <a href="results.php?quiz_id=<?php echo (int) $attempt['quiz_id']; ?>" class="btn btn-outline">&larr; Back to Results</a>
                <?php if ($next_pending): ?>
                    <a href="grade_attempt.php?attempt_id=<?php echo (int) $next_pending['id']; ?>" class="btn">Next pending &rarr;</a>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($success): ?><div class="alert alert-success"><?php echo e($success); ?></div><?php endif; ?>

        <div class="card">
            <div class="stat-grid">
                <div class="stat-card">
                    <div class="stat-value"><?php echo fmt_score($attempt['total_score'] ?? 0); ?> / <?php echo fmt_score($max_total); ?></div>
                    <div class="stat-label">Current score</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?php echo $attempt['needs_manual_grading'] ? 'Pending' : 'Complete'; ?></div>
                    <div class="stat-label">Grading status</div>
                </div>
            </div>
        </div>

        <form method="POST" id="grade-form"><?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="save_grades">
            <input type="hidden" name="attempt_id" value="<?php echo $attempt_id; ?>">

            <div class="card">
                <h2>Answers</h2>
                <p class="helptext">Auto-graded questions can still be changed here. Use the quick buttons for short answers, or type a score.</p>
                <?php if ($backfilled): ?>
                    <div class="alert alert-info">This attempt was taken before question history was recorded, so the questions below show how they looked when the upgrade ran. Any edit made before then is not reflected.</div>
                <?php endif; ?>

                <?php foreach ($rows as $idx => $a):
                    $aid = (int) $a['id'];
                    $max = (float) $a['question_points'];
                ?>
                    <section class="question-block grade-block">
                        <div class="question-head">
                            <span class="q-number">Question <?php echo $idx + 1; ?></span>
                            <span class="points-tag"><?php echo fmt_score($max); ?> pt max &middot; <?php echo e(question_type_label($a['type'])); ?><?php echo $a['points_awarded'] !== null && $a['graded_by'] === null ? ' (auto-graded)' : ''; ?></span>
                        </div>
                        <p class="question-text"><?php echo nl2br(e($a['question_text'])); ?></p>

                        <?php echo render_answer_review($a, true, true, 'teacher'); ?>
                        <?php $rule = grading_rule_summary($a); if ($rule !== ''): ?><p class="helptext"><?php echo e($rule); ?></p><?php endif; ?>
                        <?php if ($a['type'] === 'essay' && ($a['config']['guidance'] ?? '') !== ''): ?>
                            <div class="explanation-box"><strong>Marking guidance:</strong> <?php echo nl2br(e($a['config']['guidance'])); ?></div>
                        <?php endif; ?>

                        <div class="grade-controls">
                            <div class="form-field grade-points">
                                <label for="score-<?php echo $aid; ?>">Points (max <?php echo fmt_score($max); ?>)</label>
                                <input type="number" id="score-<?php echo $aid; ?>" min="0" max="<?php echo e($max); ?>" step="0.25"
                                       name="score[<?php echo $aid; ?>]"
                                       value="<?php echo $a['points_awarded'] !== null ? e($a['points_awarded']) : ''; ?>"
                                       placeholder="Not graded">
                                <?php if (!in_array($a['type'], ['mcq', 'true_false'], true) || $a['points_awarded'] !== null): ?>
                                <div class="quick-grade" data-target="score-<?php echo $aid; ?>" data-max="<?php echo e($max); ?>">
                                    <button type="button" class="btn btn-outline btn-sm" data-frac="1">Full</button>
                                    <button type="button" class="btn btn-outline btn-sm" data-frac="0.5">Half</button>
                                    <button type="button" class="btn btn-outline btn-sm" data-frac="0">Zero</button>
                                </div>
                                <?php endif; ?>
                            </div>
                            <div class="form-field grade-feedback">
                                <label for="fb-<?php echo $aid; ?>">Feedback for student (optional)</label>
                                <textarea id="fb-<?php echo $aid; ?>" name="feedback[<?php echo $aid; ?>]" rows="2" maxlength="2000"
                                          placeholder="e.g. Good reasoning, but define the term."><?php echo e($a['feedback'] ?? ''); ?></textarea>
                            </div>
                        </div>
                    </section>
                <?php endforeach; ?>
            </div>

            <div class="submit-row sticky-actions">
                <button type="submit" class="btn btn-block">Save grades</button>
            </div>
        </form>

        <?php $history = attempt_grade_history($conn, $attempt_id); if ($history): ?>
            <div class="card">
                <h2>Grade history</h2>
                <ul class="audit-list">
                    <?php foreach ($history as $h): ?>
                        <li><?php echo date('M j, Y g:i A', strtotime($h['created_at'])); ?> &middot;
                            <?php echo e($h['full_name'] ?? $h['changed_by']); ?> &middot;
                            <?php echo $h['source'] === 'regrade' ? 'Re-grade' : 'Manual score'; ?>:
                            <?php echo $h['old_points'] === null ? 'pending' : fmt_score($h['old_points']); ?> &rarr; <?php echo $h['new_points'] === null ? 'pending' : fmt_score($h['new_points']); ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
    </div>

    <script>
        // Quick-grade buttons: fill the points box with a fraction of the max.
        document.querySelectorAll('.quick-grade').forEach(group => {
            const input = document.getElementById(group.dataset.target);
            const max = parseFloat(group.dataset.max);
            group.querySelectorAll('button[data-frac]').forEach(btn => {
                btn.addEventListener('click', () => {
                    const value = Math.round(max * parseFloat(btn.dataset.frac) * 100) / 100;
                    input.value = value;
                    input.dispatchEvent(new Event('change'));
                });
            });
        });
    </script>
</body>
</html>
