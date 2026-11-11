<?php
/**
 * Practice: quizzes the student may practise, unlimited attempts with
 * instant feedback. Practice never counts towards grades.
 */
require_once __DIR__ . '/../../includes/bootstrap.php';

require_role('student', '../../auth/login.php');

$student_email = current_user_email();
$quizzes = practice_quizzes($conn, $student_email);

$stmt = $conn->prepare(
    "SELECT qa.id, qa.attempt_number, qa.submitted_at, qa.total_score, qa.max_score, qa.needs_manual_grading, q.title
     FROM quiz_attempts qa JOIN quizzes q ON q.id = qa.quiz_id
     WHERE qa.student_email = ? AND qa.is_practice = 1 AND qa.status = 'submitted'
     ORDER BY qa.submitted_at DESC LIMIT 15"
);
$stmt->bind_param("s", $student_email);
$stmt->execute();
$recent = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$active = 'practice';
$nav_base = '../';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../navStyle.css?v=9">
    <link rel="stylesheet" href="../appStyle.css?v=10">
    <title>Practice · LADDER</title>
</head>

<body>
    <?php include APP_ROOT . '/dashboards/partials/nav_student.php'; ?>

    <div class="container" id="main">
        <div class="page-header">
            <div>
                <h1>Practice</h1>
                <p class="subtitle">Practise as often as you like with instant feedback. Practice attempts never count towards your grades.</p>
            </div>
            <a class="btn btn-outline" href="bookmarks.php"><?php echo icon('bookmark'); ?> My bookmarks</a>
        </div>

        <div class="card">
            <h2>Available for practice</h2>
            <?php if (!$quizzes): ?>
                <div class="empty-state">Nothing to practise yet. Your teachers can open quizzes for practice.</div>
            <?php else: ?>
                <div class="card-list">
                    <?php foreach ($quizzes as $q): ?>
                        <div class="item-row">
                            <div>
                                <div class="item-title"><?php echo e($q['title']); ?>
                                    <?php if (($q['mode'] ?? '') === 'practice'): ?><span class="badge badge-info">Practice quiz</span><?php endif; ?></div>
                                <div class="item-meta">
                                    <?php echo (int) $q['practice_count']; ?> practice attempt(s)
                                    <?php if ($q['practice_best'] !== null): ?>&middot; Best <?php echo round($q['practice_best'] * 100); ?>%<?php endif; ?>
                                    <?php if ($q['practice_last']): ?>&middot; Last <?php echo e(date('M j, Y', strtotime($q['practice_last']))); ?><?php endif; ?>
                                </div>
                                <?php if ($q['block'] !== null): ?><div class="item-meta"><?php echo icon('clock'); ?> <?php echo e($q['block']); ?></div><?php endif; ?>
                            </div>
                            <div class="item-actions">
                                <?php if ($q['practice_open_id']): ?>
                                    <a class="btn btn-sm" href="take_quiz.php?quiz_id=<?php echo (int) $q['id']; ?>&amp;practice=1">Resume practice</a>
                                <?php elseif ($q['block'] === null): ?>
                                    <a class="btn btn-sm" href="take_quiz.php?quiz_id=<?php echo (int) $q['id']; ?>&amp;practice=1&amp;start=1">Practise</a>
                                    <?php if ($q['time_limit_minutes']): ?>
                                        <a class="btn btn-outline btn-sm" href="take_quiz.php?quiz_id=<?php echo (int) $q['id']; ?>&amp;practice=1&amp;start=1&amp;timed=1">Timed (<?php echo (int) $q['time_limit_minutes']; ?> min)</a>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="badge badge-draft">Not open yet</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($recent): ?>
            <div class="card">
                <h2>Recent practice</h2>
                <div class="table-wrap">
                    <table class="data-table" aria-label="Recent practice attempts">
                        <thead><tr><th>Quiz</th><th>Attempt</th><th>Finished</th><th>Score</th><th></th></tr></thead>
                        <tbody>
                            <?php foreach ($recent as $r): $pct = $r['max_score'] > 0 ? $r['total_score'] / $r['max_score'] * 100 : null; ?>
                                <tr>
                                    <td><?php echo e($r['title']); ?></td>
                                    <td>Practice #<?php echo (int) $r['attempt_number']; ?></td>
                                    <td><?php echo e(date('M j, Y g:i A', strtotime($r['submitted_at']))); ?></td>
                                    <td data-sort="<?php echo $pct ?? -1; ?>"><span class="score-pill <?php echo score_band($pct); ?>"><?php echo fmt_score($r['total_score']); ?> / <?php echo fmt_score($r['max_score']); ?></span></td>
                                    <td><a class="link" href="view_attempt.php?attempt_id=<?php echo (int) $r['id']; ?>">Review</a></td>
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
