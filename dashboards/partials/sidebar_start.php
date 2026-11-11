<?php
// Opening of the shared sidebar (used by nav_teacher.php / nav_student.php).
// Dashboards live in dashboards/, which is what $nav_base points to.
$home = $nav_base . basename(dashboard_for_role($_SESSION['role'] ?? ''));
?>
<script>document.documentElement.classList.add('js');</script>
<a class="skip-link" href="#main">Skip to main content</a>
<nav class="app-sidebar" id="app-sidebar" aria-label="Main navigation">
    <div class="sidebar-top">
        <a class="nav-brand" href="<?php echo e($home); ?>">
            <span class="brand-mark" aria-hidden="true">L</span>
            <span>LADDER<span class="brand-sub"><?php echo e(ucfirst($_SESSION['role'] ?? '')); ?> workspace</span></span>
        </a>
        <button type="button" class="nav-burger" aria-expanded="false" aria-controls="sidebar-body" aria-label="Open menu"><?php echo icon('menu'); ?></button>
    </div>
    <div class="sidebar-body" id="sidebar-body">
