<?php
/**
 * Send queued notification emails (users who turned email on in Settings).
 *
 *   php db/send_notifications.php          send up to 50
 *   php db/send_notifications.php 200      send up to 200
 *
 * Pages already send a few at the end of each request; run this from a cron
 * job (e.g. every 10 minutes) where the host provides one.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Command line only.\n");
}
require_once __DIR__ . '/conn.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/quiz_helpers.php';
require_once __DIR__ . '/../includes/class_helpers.php';
require_once __DIR__ . '/../includes/assignments.php';
require_once __DIR__ . '/../includes/notifications.php';

if (!mail_configured()) {
    fwrite(STDERR, "Email is not configured (email/password in .env).\n");
    exit(1);
}
$limit = max(1, (int) ($argv[1] ?? 50));
$sent = process_email_queue($conn, $limit);
echo "Processed $sent queued email(s).\n";
