<?php
/**
 * Bookmarked questions: saved from attempt reviews to revisit later, with a
 * personal note. Questions show as they were in that attempt; the correct
 * answer appears only when the student is allowed to see it.
 */
require_once __DIR__ . '/../../includes/bootstrap.php';

require_role('student', '../../auth/login.php');

$student_email = current_user_email();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['bookmark_id'] ?? 0);
    if (($_POST['action'] ?? '') === 'save_note') {
        update_bookmark_note($conn, $student_email, $id, (string) ($_POST['note'] ?? ''));
        flash('success', 'Note saved.');
    } elseif (($_POST['action'] ?? '') === 'remove') {
        if (delete_bookmark($conn, $student_email, $id)) {
            flash('success', 'Bookmark removed.');
        }
    }
    redirect('bookmarks.php#b-' . $id);
}

$bookmarks = student_bookmarks($conn, $student_email);

$active = 'bookmarks';
$nav_base = '../';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../navStyle.css?v=9">
    <link rel="stylesheet" href="../appStyle.css?v=9">
    <title>Bookmarks · LADDER</title>
</head>

<body>
    <?php include APP_ROOT . '/dashboards/partials/nav_student.php'; ?>

    <div class="container" id="main">
        <div class="page-header">
            <div>
                <h1>Bookmarked questions</h1>
                <p class="subtitle">Questions you saved to revisit. Bookmark more from any attempt review.</p>
            </div>
            <a class="btn btn-outline" href="practice.php">Practice</a>
        </div>

        <?php if (!$bookmarks): ?>
            <div class="card"><div class="empty-state">No bookmarks yet. Open a result and press <strong>Bookmark</strong> on a question you want to revisit.</div></div>
        <?php else: ?>
            <?php foreach ($bookmarks as $b): $a = $b['row']; ?>
                <section class="question-block" id="b-<?php echo (int) $b['id']; ?>">
                    <div class="question-head">
                        <span class="q-number"><?php echo e($b['quiz_title']); ?></span>
                        <span class="item-actions">
                            <span class="badge badge-info"><?php echo e(question_type_label($a['type'])); ?></span>
                            <?php if ($a['topic_name']): ?><span class="badge badge-draft"><?php echo e($a['topic_name']); ?></span><?php endif; ?>
                            <a class="link" href="view_attempt.php?attempt_id=<?php echo (int) $b['attempt']['id']; ?>">Open attempt</a>
                        </span>
                    </div>
                    <p class="question-text"><?php echo nl2br(e($a['question_text'])); ?></p>
                    <?php echo render_answer_review($a, $b['show_key'], $b['show_key'], 'student'); ?>
                    <?php if (!$b['show_key']): ?><p class="helptext">The correct answer appears here once your teacher releases it.</p><?php endif; ?>

                    <form method="POST" class="form-field" style="margin-top:12px"><?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="save_note">
                        <input type="hidden" name="bookmark_id" value="<?php echo (int) $b['id']; ?>">
                        <label for="note-<?php echo (int) $b['id']; ?>">My note</label>
                        <textarea id="note-<?php echo (int) $b['id']; ?>" name="note" rows="2" maxlength="<?php echo BOOKMARK_NOTE_MAX; ?>" placeholder="Why this one is tricky, what to remember..."><?php echo e($b['note'] ?? ''); ?></textarea>
                        <div class="item-actions">
                            <button type="submit" class="btn btn-outline btn-sm">Save note</button>
                        </div>
                    </form>
                    <form method="POST" data-confirm="Remove this bookmark?"><?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="remove">
                        <input type="hidden" name="bookmark_id" value="<?php echo (int) $b['id']; ?>">
                        <button type="submit" class="btn btn-danger-outline btn-sm">Remove bookmark</button>
                    </form>
                </section>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</body>

</html>
