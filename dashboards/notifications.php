<?php
/**
 * Notification center (teachers and students): newest first, mark one or
 * all as read; opening one marks it read and follows its link.
 */
require_once __DIR__ . '/../includes/bootstrap.php';

if (!isset($_SESSION['logged_in']) || empty($_SESSION['user_email'])) {
    redirect('../auth/login.php');
}
$role = $_SESSION['role'] ?? '';
require_role($role === 'teacher' ? 'teacher' : 'student', '../auth/login.php');
$email = current_user_email();

// Opening a notification: mark read, then follow its (in-app) link.
if (isset($_GET['open'])) {
    $n = find_notification($conn, $email, (int) $_GET['open']);
    if ($n) {
        mark_notifications_read($conn, $email, (int) $n['id']);
        $link = (string) $n['link'];
        if ($link !== '' && preg_match('#^dashboards/[A-Za-z0-9_/.\-]+\.php(\?[A-Za-z0-9_=&%.\-]*)?$#', $link)) {
            redirect('../' . $link);
        }
    }
    redirect('notifications.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['action'] ?? '') === 'read_all') {
        mark_notifications_read($conn, $email);
        flash('success', 'All notifications marked as read.');
    } elseif (($_POST['action'] ?? '') === 'read_one') {
        mark_notifications_read($conn, $email, (int) ($_POST['id'] ?? 0));
    }
    redirect('notifications.php' . (isset($_GET['unread']) ? '?unread=1' : ''));
}

if ($role === 'student') {
    notify_newly_visible_results($conn, $email);
}

$unread_only = isset($_GET['unread']);
$page = max(1, (int) ($_GET['page'] ?? 1));
$per = 25;
$list = user_notifications($conn, $email, $per, ($page - 1) * $per, $unread_only);
$pages = max(1, (int) ceil($list['total'] / $per));
$unread = unread_notification_count($conn, $email);

$active = 'notifications';
$nav_base = '';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="navStyle.css?v=9">
    <link rel="stylesheet" href="appStyle.css?v=9">
    <title>Notifications · LADDER</title>
</head>

<body>
    <?php include APP_ROOT . '/dashboards/partials/nav_' . ($role === 'teacher' ? 'teacher' : 'student') . '.php'; ?>

    <div class="container" id="main">
        <div class="page-header">
            <div>
                <h1>Notifications</h1>
                <p class="subtitle"><?php echo $unread; ?> unread. Email copies can be turned on in <a class="link" href="settings.php#notifications">Settings</a>.</p>
            </div>
            <div class="item-actions">
                <a class="btn btn-outline" href="notifications.php<?php echo $unread_only ? '' : '?unread=1'; ?>"><?php echo $unread_only ? 'Show all' : 'Unread only'; ?></a>
                <?php if ($unread): ?>
                    <form method="POST"><?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="read_all">
                        <button type="submit" class="btn">Mark all as read</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <?php if (!$list['rows']): ?>
                <div class="empty-state"><?php echo $unread_only ? 'No unread notifications.' : 'No notifications yet.'; ?></div>
            <?php else: ?>
                <div class="card-list">
                    <?php foreach ($list['rows'] as $n): $is_unread = $n['read_at'] === null; ?>
                        <div class="item-row notification<?php echo $is_unread ? ' is-unread' : ''; ?>">
                            <div>
                                <div class="item-title">
                                    <?php if ($is_unread): ?><span class="unread-dot" aria-label="Unread"></span><?php endif; ?>
                                    <a class="link" href="notifications.php?open=<?php echo (int) $n['id']; ?>"><?php echo e($n['title']); ?></a>
                                </div>
                                <?php if ($n['body']): ?><div class="item-meta"><?php echo e($n['body']); ?></div><?php endif; ?>
                                <div class="item-meta"><?php echo e(NOTIFICATION_TYPES[$n['type']] ?? ucfirst($n['type'])); ?> &middot; <?php echo e(date('M j, Y g:i A', strtotime($n['created_at']))); ?></div>
                            </div>
                            <?php if ($is_unread): ?>
                                <div class="item-actions">
                                    <form method="POST"><?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="read_one">
                                        <input type="hidden" name="id" value="<?php echo (int) $n['id']; ?>">
                                        <button type="submit" class="btn btn-outline btn-sm">Mark read</button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php if ($pages > 1): ?>
                    <nav class="table-pager" aria-label="Notification pages">
                        <span>Page <?php echo $page; ?> of <?php echo $pages; ?></span>
                        <?php if ($page > 1): ?><a class="btn btn-outline btn-sm" href="notifications.php?page=<?php echo $page - 1; ?><?php echo $unread_only ? '&amp;unread=1' : ''; ?>">Previous</a><?php endif; ?>
                        <?php if ($page < $pages): ?><a class="btn btn-outline btn-sm" href="notifications.php?page=<?php echo $page + 1; ?><?php echo $unread_only ? '&amp;unread=1' : ''; ?>">Next</a><?php endif; ?>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</body>

</html>
