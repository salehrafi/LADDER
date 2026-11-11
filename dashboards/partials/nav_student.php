<?php
// Student sidebar. Pages set $active (which link is current) and $nav_base
// (path from the page to dashboards/).
$active = $active ?? '';
$nav_base = $nav_base ?? '';
require __DIR__ . '/sidebar_start.php';
?>
        <p class="nav-section">Learning</p>
        <ul class="navBar">
            <?php echo nav_link($nav_base . 'studentDashboard.php', 'Dashboard', 'home', 'home', $active); ?>
            <?php echo nav_link($nav_base . 'studentPages/quizzes.php', 'Quizzes', 'quiz', 'quizzes', $active); ?>
            <?php echo nav_link($nav_base . 'studentPages/results.php', 'My Results', 'results', 'results', $active); ?>
            <?php echo nav_link($nav_base . 'studentPages/progress.php', 'My Progress', 'chart', 'progress', $active); ?>
            <?php echo nav_link($nav_base . 'studentPages/practice.php', 'Practice', 'practice', 'practice', $active); ?>
            <?php echo nav_link($nav_base . 'studentPages/bookmarks.php', 'Bookmarks', 'bookmark', 'bookmarks', $active); ?>
        </ul>
        <p class="nav-section">Account</p>
        <ul class="navBar">
            <?php echo nav_link($nav_base . 'notifications.php', 'Notifications', 'bell', 'notifications', $active, unread_notification_count($conn, current_user_email())); ?>
            <?php echo nav_link($nav_base . 'settings.php', 'Settings', 'settings', 'settings', $active); ?>
        </ul>
<?php require __DIR__ . '/sidebar_end.php'; ?>
