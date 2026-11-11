<?php
// Teacher sidebar. Pages set $active (which link is current) and $nav_base
// (path from the page to dashboards/).
$active = $active ?? '';
$nav_base = $nav_base ?? '';
require __DIR__ . '/sidebar_start.php';
?>
        <p class="nav-section">Teaching</p>
        <ul class="navBar">
            <?php echo nav_link($nav_base . 'teacherDashboard.php', 'Dashboard', 'home', 'home', $active); ?>
            <?php echo nav_link($nav_base . 'teachersPages/setQuiz.php', 'Quizzes', 'quiz', 'quizzes', $active); ?>
            <?php echo nav_link($nav_base . 'teachersPages/question_bank.php', 'Question Bank', 'bank', 'bank', $active); ?>
            <?php echo nav_link($nav_base . 'teachersPages/results.php', 'Results', 'results', 'results', $active); ?>
            <?php echo nav_link($nav_base . 'teachersPages/analytics.php', 'Analytics', 'chart', 'analytics', $active); ?>
        </ul>
        <p class="nav-section">People</p>
        <ul class="navBar">
            <?php echo nav_link($nav_base . 'teachersPages/classes.php', 'Classes', 'classes', 'classes', $active); ?>
            <?php echo nav_link($nav_base . 'teachersPages/student_profile.php', 'Students', 'student', 'students', $active); ?>
        </ul>
        <p class="nav-section">Account</p>
        <ul class="navBar">
            <?php echo nav_link($nav_base . 'notifications.php', 'Notifications', 'bell', 'notifications', $active, unread_notification_count($conn, current_user_email())); ?>
            <?php echo nav_link($nav_base . 'settings.php', 'Settings', 'settings', 'settings', $active); ?>
        </ul>
<?php require __DIR__ . '/sidebar_end.php'; ?>
