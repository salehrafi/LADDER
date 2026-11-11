<?php
require_once __DIR__ . '/../includes/bootstrap.php';

const RESET_WINDOW_SECONDS = 900; // time to choose a password after the code is accepted

$error = "";
$email = $_SESSION['email'] ?? null;

// Only reachable shortly after a successful code check (otp_verification.php);
// otherwise anyone who merely requested a code for an address could reset
// that account's password without proving they own the inbox.
if (!$email || empty($_SESSION['otp_verified'])
    || time() - (int) ($_SESSION['otp_verified_at'] ?? 0) > RESET_WINDOW_SECONDS) {
    unset($_SESSION['otp_verified'], $_SESSION['otp_verified_at']);
    header("Location: forget_password.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === "POST") {
    verify_csrf();
    $pass = (string) ($_POST['pass'] ?? '');
    $repass = (string) ($_POST['repass'] ?? '');

    if ($rule = password_rule_error($pass)) {
        $error = $rule;
    } elseif ($pass !== $repass) {
        $error = "The passwords do not match.";
    } else {
        $hashed_password = password_hash($pass, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE reg_info SET pass = ?, must_change_password = 0 WHERE email = ?");
        $stmt->bind_param("ss", $hashed_password, $email);
        $stmt->execute();
        $stmt->close();

        // Anyone still signed in with the old password is signed out, and
        // earlier failed logins no longer block the account.
        bump_auth_version($conn, $email);
        throttle_clear($conn, 'login_email', $email);

        // The reset is complete: clear the one-time state so it can't be reused.
        unset($_SESSION['otp_verified'], $_SESSION['otp_verified_at'], $_SESSION['email']);
        flash('success', 'Your password was changed. Sign in with your new password.');
        header("Location: login.php");
        exit;
    }
}

auth_layout_start('Choose a new password', 'Choose a new password', PASSWORD_MIN_LENGTH . '-' . PASSWORD_MAX_LENGTH . ' characters.');
?>
            <form method="POST">
                <?php echo csrf_field(); ?>
                <div class="field">
                    <label for="pass">New password</label>
                    <input type="password" name="pass" id="pass" minlength="<?php echo PASSWORD_MIN_LENGTH; ?>" maxlength="<?php echo PASSWORD_MAX_LENGTH; ?>" autocomplete="new-password" required autofocus>
                </div>
                <div class="field">
                    <label for="repass">Repeat new password</label>
                    <input type="password" name="repass" id="repass" minlength="<?php echo PASSWORD_MIN_LENGTH; ?>" maxlength="<?php echo PASSWORD_MAX_LENGTH; ?>" autocomplete="new-password" required>
                </div>
                <?php echo auth_alert($error); ?>
                <button type="submit" class="btn btn-primary btn-block">Change password</button>
            </form>
<?php auth_layout_end();
