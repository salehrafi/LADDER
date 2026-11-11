<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$error = "";
$email = $_SESSION['email'] ?? null;

if (!$email) {
    header("Location: forget_password.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === "POST") {
    verify_csrf();

    if (verify_password_reset_code($conn, $email, (string) ($_POST['otp'] ?? ''))) {
        // password_change.php requires this flag (for a limited time) before allowing a reset.
        session_regenerate_id(true);
        $_SESSION['otp_verified'] = true;
        $_SESSION['otp_verified_at'] = time();
        header("Location: password_change.php");
        exit;
    }
    // One message for every failure: wrong, expired, used up, or unknown email.
    $error = "That code is not valid or has expired. Check the latest email, or request a new code.";
}

auth_layout_start('Enter reset code', 'Enter your reset code',
    "If $email has an account, we've emailed it a 6-digit code. It expires in " . OTP_LIFETIME_MINUTES . " minutes.");
?>
            <form method="POST" autocomplete="off">
                <?php echo csrf_field(); ?>
                <div class="field">
                    <label for="otp">6-digit code</label>
                    <input type="text" name="otp" id="otp" class="otp-input" inputmode="numeric" pattern="[0-9]{6}" maxlength="6"
                        autocomplete="one-time-code" placeholder="000000" required autofocus>
                </div>
                <?php echo auth_alert($error); ?>
                <button type="submit" class="btn btn-primary btn-block">Verify code</button>
            </form>
            <p class="links"><a href="forget_password.php">Request a new code</a> &middot; <a href="login.php">Back to sign in</a></p>
<?php auth_layout_end();
