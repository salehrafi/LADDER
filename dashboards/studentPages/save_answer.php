<?php
// Autosave endpoint for the take-quiz page. Receives the student's current
// answers as JSON and stores them as a draft on their in-progress attempt.
require_once __DIR__ . '/../../includes/bootstrap.php';

$body = require_role_json('student');

$attempt_id = (int) ($body['attempt_id'] ?? 0);
$raw_answers = is_array($body['answers'] ?? null) ? $body['answers'] : [];

$attempt = load_attempt_for_student($conn, $attempt_id, current_user_email());
if (!$attempt) {
    json_response(409, ['ok' => false, 'error' => 'attempt_closed']);
}

$deadline = attempt_deadline_epoch($attempt);
if ($deadline !== null && time() > $deadline + QUIZ_SUBMIT_GRACE_SECONDS) {
    json_response(409, ['ok' => false, 'error' => 'time_up']);
}

$clean = sanitize_answers($conn, $attempt, $raw_answers);
save_draft($conn, $attempt_id, $clean);

json_response(200, ['ok' => true, 'saved_at' => date('H:i:s'), 'count' => count($clean)]);
