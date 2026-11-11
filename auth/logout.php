<?php
// Logging out changes state, so it takes a POST with the CSRF token (a link
// or image on another site cannot sign the user out). A plain visit shows a
// confirm button instead.
require_once __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $_SESSION = [];
    session_regenerate_id(true);
    session_destroy();
    header("Location: login.php");
    exit();
}

if (empty($_SESSION['logged_in'])) {
    header("Location: login.php");
    exit();
}

auth_layout_start('Log out', 'Log out?', 'You can sign back in at any time.');
?>
            <form method="POST">
                <?php echo csrf_field(); ?>
                <button type="submit" class="btn btn-primary btn-block">Log out</button>
            </form>
            <p class="links"><a href="../<?php echo e(dashboard_for_role($_SESSION['role'] ?? '')); ?>">Back to the dashboard</a></p>
<?php auth_layout_end();
