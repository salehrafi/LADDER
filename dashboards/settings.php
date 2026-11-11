<?php
require_once __DIR__ . '/../includes/bootstrap.php';

if (!isset($_SESSION['logged_in']) || empty($_SESSION['user_email'])) {
    header("Location: ../auth/login.php");
    exit();
}
require_password_set('../auth/login.php');
verify_csrf();

$role = $_SESSION['role'];
$email = current_user_email();
$success = "";
$error = "";

if ($_SERVER['REQUEST_METHOD'] === "POST") {
    $form = $_POST['form'] ?? '';

    if (is_demo_session()) {
        // Shared by every visitor: keep the login working for the next one.
        $error = "This is a shared demo account, so its settings can't be changed. Create your own account to personalise it.";
    } elseif ($form === 'profile') {
        $new_name = trim($_POST['full_name'] ?? '');
        if ($new_name === '') {
            $error = "Name cannot be empty.";
        } elseif (mb_strlen($new_name) > 50) {
            $error = "Name must be 50 characters or fewer.";
        } else {
            $stmt = $conn->prepare("UPDATE reg_info SET full_name = ? WHERE email = ?");
            $stmt->bind_param("ss", $new_name, $email);
            $stmt->execute();
            $stmt->close();
            $_SESSION['name'] = $new_name;
            $success = "Profile updated.";
        }
    } elseif ($form === 'notifications') {
        $on = isset($_POST['email_notifications']) ? 1 : 0;
        $stmt = $conn->prepare("UPDATE reg_info SET email_notifications = ? WHERE email = ?");
        $stmt->bind_param("is", $on, $email);
        $stmt->execute();
        $stmt->close();
        $success = $on ? "Email notifications turned on." : "Email notifications turned off.";
    } elseif ($form === 'password') {
        $current = $_POST['current_password'] ?? '';
        $new_pass = $_POST['new_password'] ?? '';
        $repeat = $_POST['repeat_password'] ?? '';

        $stmt = $conn->prepare("SELECT pass FROM reg_info WHERE email = ? LIMIT 1");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row || !password_verify($current, $row['pass'])) {
            $error = "Current password is incorrect.";
        } elseif ($rule = password_rule_error((string) $new_pass)) {
            $error = $rule;
        } elseif ($new_pass !== $repeat) {
            $error = "New passwords do not match.";
        } else {
            $hashed = password_hash($new_pass, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE reg_info SET pass = ? WHERE email = ?");
            $stmt->bind_param("ss", $hashed, $email);
            $stmt->execute();
            $stmt->close();
            // Sign out every other device; this session stays signed in.
            bump_auth_version($conn, $email, true);
            $success = "Password updated. Any other device signed in to your account was signed out.";
        }
    }
}

$stmt = $conn->prepare("SELECT id, full_name, email, email_notifications FROM reg_info WHERE email = ? LIMIT 1");
$stmt->bind_param("s", $email);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

$active = 'settings';
$nav_base = '';
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="navStyle.css?v=9">
    <link rel="stylesheet" href="appStyle.css?v=9">
    <title>Account Settings</title>
</head>

<body>
    <?php include APP_ROOT . '/dashboards/partials/nav_' . $role . '.php'; ?>

    <div class="container" id="main">
        <div class="page-header">
            <div>
                <h1>Account Settings</h1>
                <p class="subtitle">Manage your profile and password.</p>
            </div>
        </div>

        <?php if ($success): ?><div class="alert alert-success"><?php echo e($success); ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-error"><?php echo e($error); ?></div><?php endif; ?>

        <div class="card">
            <h2>Profile</h2>
            <form method="POST"><?php echo csrf_field(); ?>
                <input type="hidden" name="form" value="profile">
                <div class="form-grid">
                    <div class="form-field">
                        <label for="user-id">ID</label>
                        <input type="text" id="user-id" value="<?php echo e($user['id']); ?>" readonly aria-readonly="true">
                    </div>
                    <div class="form-field">
                        <label for="user-email">Email</label>
                        <input type="text" id="user-email" value="<?php echo e($user['email']); ?>" readonly aria-readonly="true">
                    </div>
                    <div class="form-field">
                        <label for="user-role">Role</label>
                        <input type="text" id="user-role" value="<?php echo e(ucfirst($role)); ?>" readonly aria-readonly="true">
                    </div>
                    <div class="form-field">
                        <label for="full_name">Full Name</label>
                        <input type="text" id="full_name" name="full_name" value="<?php echo e($user['full_name']); ?>" required>
                    </div>
                </div>
                <button type="submit" class="btn">Save Profile</button>
            </form>
        </div>

        <div class="card">
            <h2>Change Password</h2>
            <form method="POST"><?php echo csrf_field(); ?>
                <input type="hidden" name="form" value="password">
                <div class="form-grid">
                    <div class="form-field">
                        <label for="current_password">Current Password</label>
                        <input type="password" id="current_password" name="current_password" required>
                    </div>
                    <div class="form-field">
                        <label for="new_password">New Password</label>
                        <input type="password" id="new_password" name="new_password" minlength="6" maxlength="64" required>
                    </div>
                    <div class="form-field">
                        <label for="repeat_password">Repeat New Password</label>
                        <input type="password" id="repeat_password" name="repeat_password" minlength="6" maxlength="64" required>
                    </div>
                </div>
                <p class="helptext">6-64 characters.</p>
                <button type="submit" class="btn">Update Password</button>
            </form>
        </div>

        <div class="card" id="notifications">
            <h2>Notifications</h2>
            <p class="helptext">You always see notifications in the app (the bell in the menu). You can also get a copy by email
                <?php echo $role === 'teacher' ? '(answers to evaluate)' : '(new quizzes, deadlines, results and feedback)'; ?>.</p>
            <form method="POST"><?php echo csrf_field(); ?>
                <input type="hidden" name="form" value="notifications">
                <div class="check-row">
                    <label class="form-check"><input type="checkbox" name="email_notifications" value="1" <?php echo (int) $user['email_notifications'] === 1 ? 'checked' : ''; ?>> Email me a copy of my notifications</label>
                </div>
                <?php if (!mail_configured()): ?><p class="helptext">Email is not set up on this server yet, so only in-app notifications are sent.</p><?php endif; ?>
                <button type="submit" class="btn btn-outline">Save notification settings</button>
            </form>
        </div>
    </div>
</body>

</html>
