<?php
// Lock-mode endpoint. The take-quiz page calls this (via navigator.sendBeacon)
// when the student switches tab/app, minimises or closes the page during a
// locked quiz. It grades and closes the attempt with reason "left_page".
require_once __DIR__ . '/../../includes/bootstrap.php';

$body = require_role_json('student');

$attempt = load_attempt_for_student($conn, (int) ($body['attempt_id'] ?? 0), current_user_email());
if (!$attempt) {
    json_response(409, ['ok' => false, 'error' => 'attempt_closed']);
}

// Practice attempts are never locked.
if ((int) $attempt['is_practice'] === 1) {
    json_response(409, ['ok' => false, 'error' => 'not_locked']);
}

// Only quizzes with lock mode on can be closed this way.
$stmt = $conn->prepare("SELECT lock_on_leave FROM quizzes WHERE id = ?");
$stmt->bind_param("i", $attempt['quiz_id']);
$stmt->execute();
$locked = (int) ($stmt->get_result()->fetch_assoc()['lock_on_leave'] ?? 0) === 1;
$stmt->close();
if (!$locked) {
    json_response(409, ['ok' => false, 'error' => 'not_locked']);
}

$raw = is_array($body['answers'] ?? null) ? $body['answers'] : [];
$closed = submit_on_leave($conn, $attempt, $raw, (string) ($body['reason'] ?? 'left_page'));

json_response(200, ['ok' => true, 'closed' => $closed, 'attempt_id' => (int) $attempt['id']]);
