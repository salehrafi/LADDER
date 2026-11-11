<?php
// Practice mode: instant feedback on one question. Only for the student's
// own in-progress practice attempt; official attempts are refused, so this
// can never reveal answers during a graded quiz. Nothing is stored.
require_once __DIR__ . '/../../includes/bootstrap.php';

$body = require_role_json('student');

$attempt = load_attempt_for_student($conn, (int) ($body['attempt_id'] ?? 0), current_user_email());
if (!$attempt || (int) $attempt['is_practice'] !== 1) {
    json_response(403, ['ok' => false, 'error' => 'not_practice']);
}

$result = check_practice_answer($conn, $attempt, (int) ($body['question_id'] ?? 0), $body['answer'] ?? null);
if ($result === null) {
    json_response(404, ['ok' => false, 'error' => 'unknown_question']);
}
json_response(200, ['ok' => true] + $result);
