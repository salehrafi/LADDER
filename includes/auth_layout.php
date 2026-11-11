<?php
/**
 * Shared frame for the small auth pages (password reset, first login, log
 * out): the LADDER brand above one card, styled by auth/auth.css.
 */

function auth_layout_start(string $title, string $heading, string $sub = ''): void
{
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0B1220">
    <title><?php echo e($title); ?> · LADDER</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="auth.css?v=2">
</head>
<body>
    <main class="auth-main auth-single">
        <a class="brand brand-mobile" href="../index.php"><span class="mark"><svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M8 3v18M16 3v18M8 17h8M8 12h8M8 7h8"/></svg></span>LADDER</a>
        <div class="card">
            <header class="card-head">
                <h1><?php echo e($heading); ?></h1>
                <?php if ($sub !== ''): ?><p><?php echo e($sub); ?></p><?php endif; ?>
            </header>
    <?php
}

function auth_layout_end(): void
{
    ?>
        </div>
    </main>
</body>
</html>
    <?php
}

/** An alert box, or nothing for an empty message. */
function auth_alert(string $message, string $type = 'error'): string
{
    if ($message === '') {
        return '';
    }
    $role = $type === 'error' ? 'alert' : 'status';
    return '<p class="alert alert-' . e($type) . '" role="' . $role . '">' . e($message) . '</p>';
}
