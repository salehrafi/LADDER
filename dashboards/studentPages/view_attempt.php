<?php
require_once __DIR__ . '/../../includes/bootstrap.php';

require_role('student', '../../auth/login.php');

$student_email = current_user_email();
$attempt_id = (int) ($_GET['attempt_id'] ?? 0);

$stmt = $conn->prepare(
    "SELECT qa.*, q.title AS quiz_title, q.status AS quiz_status, q.end_time, q.results_release,
            q.results_released_at, q.show_answers
     FROM quiz_attempts qa
     JOIN quizzes q ON q.id = qa.quiz_id
     WHERE qa.id = ? AND qa.student_email = ? AND qa.status = 'submitted'"
);
$stmt->bind_param("is", $attempt_id, $student_email);
$stmt->execute();
$attempt = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$attempt) {
    header("Location: quizzes.php");
    exit;
}
$is_practice = (int) $attempt['is_practice'] === 1;

// Bookmark a question of this attempt (to revisit on the Bookmarks page).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_bookmark') {
    $qid = (int) ($_POST['question_id'] ?? 0);
    try {
        flash('success', toggle_bookmark($conn, $student_email, $attempt_id, $qid) ? 'Question bookmarked.' : 'Bookmark removed.');
    } catch (InvalidArgumentException $e) {
        flash('error', $e->getMessage());
    }
    redirect("view_attempt.php?attempt_id=$attempt_id#q-$qid");
}

// The teacher decides when scores, feedback and correct answers are shown.
// Practice attempts are for learning: answers are always shown.
$quiz_view = ['status' => $attempt['quiz_status']] + $attempt;
$released = $is_practice || results_released($quiz_view);
$show_answers = $is_practice || answers_visible($quiz_view);
$bookmarked = attempt_bookmarks($conn, $student_email, $attempt_id);

// The questions exactly as this attempt was taken (snapshot), with the answers.
$rows = attempt_review_rows($conn, $attempt);

$active = $is_practice ? 'practice' : 'results';
$nav_base = '../';
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../navStyle.css?v=10">
    <link rel="stylesheet" href="../appStyle.css?v=10">
    <title>Attempt Review</title>
</head>

<body>
    <?php include APP_ROOT . '/dashboards/partials/nav_student.php'; ?>

    <div class="container" id="main">
        <div class="report-header print-only">
            <h1>Result report: <?php echo e($attempt['quiz_title']); ?></h1>
            <div><?php echo e($_SESSION['name'] ?? ''); ?> (<?php echo e($student_email); ?>) &middot; Generated <?php echo e(date('M j, Y g:i A')); ?></div>
        </div>
        <div class="page-header">
            <div>
                <h1><?php echo e($attempt['quiz_title']); ?></h1>
                <p class="subtitle"><?php echo $is_practice ? '<span class="badge badge-info">Practice</span> Practice attempt' : 'Attempt'; ?> #<?php echo (int) $attempt['attempt_number']; ?> &middot; Submitted <?php echo $attempt['submitted_at'] ? date('M j, Y g:i A', strtotime($attempt['submitted_at'])) : '-'; ?></p>
            </div>
            <?php if ($is_practice): ?>
                <div class="item-actions">
                    <a href="take_quiz.php?quiz_id=<?php echo (int) $attempt['quiz_id']; ?>&amp;practice=1&amp;start=1" class="btn">Practise again</a>
                    <a href="practice.php" class="btn btn-outline">&larr; Practice</a>
                </div>
            <?php else: ?>
                <div class="item-actions">
                    <?php if ($released): ?><button type="button" class="btn btn-outline" data-print><?php echo icon('print'); ?> Print / Save as PDF</button><?php endif; ?>
                    <a href="results.php?quiz_id=<?php echo (int) $attempt['quiz_id']; ?>" class="btn btn-outline">&larr; Back to results</a>
                </div>
            <?php endif; ?>
        </div>

        <div class="card">
            <?php if ($released): ?>
                <div class="stat-grid">
                    <div class="stat-card">
                        <div class="stat-value"><?php echo fmt_score($attempt['total_score'] ?? 0); ?> / <?php echo fmt_score($attempt['max_score']); ?></div>
                        <div class="stat-label">Score</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value"><?php echo $attempt['needs_manual_grading'] ? 'Pending' : 'Final'; ?></div>
                        <div class="stat-label">Grading status</div>
                    </div>
                </div>
            <?php else: ?>
                <div class="alert alert-info">Your answers were submitted. <?php echo e(results_pending_note($quiz_view)); ?></div>
            <?php endif; ?>
            <?php if (($attempt['submit_reason'] ?? '') === 'left_page'): ?>
                <div class="alert alert-error">You left the quiz page (switched tab or app, minimised, or closed it), so this attempt was submitted automatically.</div>
            <?php elseif (($attempt['submit_reason'] ?? '') === 'exit_fullscreen'): ?>
                <div class="alert alert-error">You left full screen and did not return in time, so this attempt was submitted automatically.</div>
            <?php elseif (isset($_GET['expired']) || ($attempt['submit_reason'] ?? '') === 'time_up'): ?>
                <div class="alert alert-info">Time ran out, so this attempt was submitted automatically with your last saved answers.</div>
            <?php endif; ?>
            <?php if ($released && $attempt['needs_manual_grading']): ?>
                <div class="alert alert-info">One or more short-answer questions are still awaiting your teacher's review. The score above may change.</div>
            <?php endif; ?>
        </div>

        <div class="card">
            <h2>Your answers</h2>
            <?php foreach ($rows as $idx => $a): $is_marked = in_array((int) $a['question_id'], $bookmarked, true); ?>
                <section class="question-block" id="q-<?php echo (int) $a['question_id']; ?>">
                    <div class="question-head">
                        <span class="q-number">Question <?php echo $idx + 1; ?></span>
                        <form method="POST" class="bookmark-form"><?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="toggle_bookmark">
                            <input type="hidden" name="question_id" value="<?php echo (int) $a['question_id']; ?>">
                            <button type="submit" class="btn btn-outline btn-sm bookmark-btn" aria-pressed="<?php echo $is_marked ? 'true' : 'false'; ?>"><?php echo icon('bookmark'); ?> <?php echo $is_marked ? 'Bookmarked' : 'Bookmark'; ?></button>
                        </form>
                        <?php if (!$released): ?>
                            <span class="points-tag"><?php echo fmt_score($a['question_points']); ?> pt</span>
                        <?php elseif ($a['is_correct'] === null): ?>
                            <span class="badge badge-pending">Pending review</span>
                        <?php elseif ($a['is_correct']): ?>
                            <span class="badge badge-open">Correct &middot; <?php echo fmt_score($a['points_awarded']); ?>/<?php echo fmt_score($a['question_points']); ?> pt</span>
                        <?php else: ?>
                            <span class="badge badge-closed">
                                <?php echo ($a['points_awarded'] ?? 0) > 0 ? 'Partial' : 'Incorrect'; ?>
                                &middot; <?php echo fmt_score($a['points_awarded']); ?>/<?php echo fmt_score($a['question_points']); ?> pt
                            </span>
                        <?php endif; ?>
                    </div>
                    <p class="question-text"><?php echo nl2br(e($a['question_text'])); ?></p>

                    <?php echo render_answer_review($a, $show_answers, $released, 'student'); ?>

                    <?php if ($released && !empty($a['feedback'])): ?>
                        <div class="feedback-box">
                            <strong>Teacher feedback:</strong>
                            <?php echo nl2br(e($a['feedback'])); ?>
                        </div>
                    <?php endif; ?>
                </section>
            <?php endforeach; ?>
        </div>
    </div>
</body>

</html>
