<?php
/**
 * Set the password of any account from the command line: for a teacher who
 * is locked out, or when no email is configured for the OTP reset flow.
 *
 *     php db/set_password.php teacher@school.edu "NewPass@123"
 *     php db/set_password.php teacher@school.edu "Temp@1234" --must-change
 *
 * With --must-change the user has to pick a new password at their next login.
 * The password must follow the app's password rule (6-64 characters).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Run this from the command line.\n");
}

require_once __DIR__ . '/conn.php';
require_once __DIR__ . '/../includes/helpers.php';

$args = array_values(array_filter(array_slice($argv, 1), fn($a) => strpos($a, '--') !== 0));
$must_change = in_array('--must-change', $argv, true) ? 1 : 0;
[$email, $password] = array_pad($args, 2, null);

if (!$email || $password === null) {
    fwrite(STDERR, "Usage: php db/set_password.php <email> <new password> [--must-change]\n");
    exit(1);
}
if ($rule = password_rule_error($password)) {
    fwrite(STDERR, "$rule\n");
    exit(1);
}

$hash = password_hash($password, PASSWORD_DEFAULT);
$stmt = $conn->prepare("UPDATE reg_info SET pass = ?, must_change_password = ?, otp = NULL, exp_time = NULL WHERE email = ?");
$stmt->bind_param("sis", $hash, $must_change, $email);
$stmt->execute();
$found = $conn->query("SELECT 1 FROM reg_info WHERE email = '" . $conn->real_escape_string($email) . "'")->num_rows === 1;
$stmt->close();

if ($found) {
    // Sign the account out everywhere and lift any login lock-out.
    bump_auth_version($conn, $email);
    throttle_clear($conn, 'login_email', $email);
}

if (!$found) {
    fwrite(STDERR, "No account with email $email.\n");
    exit(1);
}
echo "Password updated for $email" . ($must_change ? " (must change at next login)" : "") . ".\n";
