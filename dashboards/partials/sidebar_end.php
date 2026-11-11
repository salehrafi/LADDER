<?php
// Closing of the shared sidebar: the signed-in user and log out, then the
// toast region for flash messages and the shared script.
?>
        <div class="sidebar-user">
            <div class="user-name"><?php echo e($_SESSION['name'] ?? ''); ?></div>
            <div class="user-role"><?php echo e($_SESSION['role'] ?? ''); ?></div>
            <form method="POST" action="<?php echo e($nav_base); ?>../auth/logout.php" class="logout-form"><?php echo csrf_field(); ?>
                <button type="submit" class="logout-btn"><?php echo icon('logout'); ?> Log out</button>
            </form>
        </div>
    </div>
</nav>
<?php echo render_toasts(); ?>
<script src="<?php echo e($nav_base); ?>../assets/app.js" defer></script>
