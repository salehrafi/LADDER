<?php
// Shown right after logging in with a default password set by a teacher:
// the user must choose their own password before using the app.
require_once __DIR__ . '/../includes/bootstrap.php';

if (!isset($_SESSION['logged_in']) || empty($_SESSION['user_email'])) {
    header("Location: login.php");
    exit();
}
if (empty($_SESSION['must_change_password'])) {
    header('Location: ../' . dashboard_for_role($_SESSION['role'] ?? ''));
    exit();
}
verify_csrf();

$email = current_user_email();
$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_pass = (string) ($_POST['new_password'] ?? '');
    $repeat = (string) ($_POST['repeat_password'] ?? '');

    $stmt = $conn->prepare("SELECT pass FROM reg_info WHERE email = ? LIMIT 1");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($rule = password_rule_error($new_pass)) {
        $error = $rule;
    } elseif ($new_pass !== $repeat) {
        $error = "The passwords do not match.";
    } elseif ($row && password_verify($new_pass, $row['pass'])) {
        $error = "Choose a new password, not the default one you were given.";
    } else {
        complete_password_change($conn, $email, $new_pass);
        $_SESSION['must_change_password'] = false;
        session_regenerate_id(true);
        header('Location: ../' . dashboard_for_role($_SESSION['role'] ?? ''));
        exit();
    }
}

auth_layout_start('Set your password', 'Set your password',
    'Welcome, ' . ($_SESSION['name'] ?? '') . '. You signed in with a temporary password from your teacher. Choose your own ('
    . PASSWORD_MIN_LENGTH . '-' . PASSWORD_MAX_LENGTH . ' characters) to continue.');
?>
            <form method="POST" autocomplete="off">
                <?php echo csrf_field(); ?>
                <div class="field">
                    <label for="new_password">New password</label>
                    <input type="password" name="new_password" id="new_password" minlength="<?php echo PASSWORD_MIN_LENGTH; ?>" maxlength="<?php echo PASSWORD_MAX_LENGTH; ?>" autocomplete="new-password" required autofocus>
                </div>
                <div class="field">
                    <label for="repeat_password">Repeat new password</label>
                    <input type="password" name="repeat_password" id="repeat_password" minlength="<?php echo PASSWORD_MIN_LENGTH; ?>" maxlength="<?php echo PASSWORD_MAX_LENGTH; ?>" autocomplete="new-password" required>
                </div>
                <?php echo auth_alert($error); ?>
                <button type="submit" class="btn btn-primary btn-block">Save and continue</button>
            </form>
            <form method="POST" action="logout.php">
                <?php echo csrf_field(); ?>
                <p class="links"><button type="submit" class="link-button">Log out</button></p>
            </form>
<?php auth_layout_end();
