<?php
/**
 * Target of the link in the verification email. A valid, unexpired token
 * activates the account and sends the user to sign in; anything else explains
 * how to get a new link.
 */
require_once __DIR__ . '/../includes/bootstrap.php';

$token = (string) ($_GET['token'] ?? '');
$email = verify_email_token($conn, $token);

if ($email !== null) {
    flash('success', 'Your email is verified. You can sign in now.');
    redirect('login.php');
}

flash('error', 'That verification link is invalid or has expired. Sign in with your email and password to get a new one.');
redirect('login.php');
