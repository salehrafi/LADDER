<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$error = "";

if ($_SERVER['REQUEST_METHOD'] === "POST") {
    verify_csrf();
    $mail_address = mb_strtolower(trim((string) ($_POST['mailTo'] ?? '')));

    if (!filter_var($mail_address, FILTER_VALIDATE_EMAIL)) {
        $error = "Enter a valid email address.";
    } elseif (throttle_blocked($conn, 'otp_request_email', $mail_address) || throttle_blocked($conn, 'otp_request_ip', client_ip())) {
        $error = "Too many reset requests. Wait 15 minutes and try again.";
    } else {
        // Counted for every address, registered or not, so the limit itself
        // reveals nothing.
        throttle_record($conn, 'otp_request_email', $mail_address);
        throttle_record($conn, 'otp_request_ip', client_ip());

        // Only an active account gets a code; the page looks the same either way.
        $otp_code = issue_password_reset_code($conn, $mail_address);
        if ($otp_code !== null) {
            $minutes = OTP_LIFETIME_MINUTES;
            send_app_mail(
                $mail_address,
                'Your LADDER password reset code',
                '<div style="font-family:Arial,sans-serif;line-height:1.6;color:#0B1220">'
                    . '<p style="font-size:18px;font-weight:bold;letter-spacing:2px;color:#4F46E5">LADDER</p>'
                    . '<p>Your password reset code is:</p>'
                    . '<p style="font-size:26px;font-weight:bold;letter-spacing:6px">' . e($otp_code) . '</p>'
                    . "<p>It expires in $minutes minutes. If you did not ask to reset your password, ignore this email.</p></div>",
                "Your LADDER password reset code is $otp_code. It expires in $minutes minutes."
            );
        } else {
            // Server-side only: the visitor sees the same page either way.
            error_log("Reset code not sent: no active account for the requested address.");
        }

        // Same outcome whether or not the email is registered, so this page
        // cannot be used to find out who has an account.
        $_SESSION['email'] = $mail_address;
        unset($_SESSION['otp_verified'], $_SESSION['otp_verified_at']);
        header("Location: otp_verification.php");
        exit;
    }
}

auth_layout_start('Reset password', 'Forgot your password?', "Enter your account's email and we'll send you a 6-digit reset code.");
?>
            <form method="POST" novalidate>
                <?php echo csrf_field(); ?>
                <div class="field">
                    <label for="mailTo">Email</label>
                    <input type="email" name="mailTo" id="mailTo" placeholder="you@school.edu" autocomplete="email" maxlength="100" required autofocus>
                </div>
                <?php echo auth_alert($error); ?>
                <button type="submit" class="btn btn-primary btn-block">Send reset code</button>
            </form>
            <p class="links"><a href="login.php">Back to sign in</a></p>
<?php auth_layout_end();
