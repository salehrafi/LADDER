<?php
// Exam-mode start gate. Called when the student clicks "Start in full screen"
// on the take-quiz page; starts the countdown (once) and returns the timing
// the page should use.
require_once __DIR__ . '/../../includes/bootstrap.php';

$body = require_role_json('student');

$attempt = load_attempt_for_student($conn, (int) ($body['attempt_id'] ?? 0), current_user_email());
if (!$attempt) {
    json_response(409, ['ok' => false, 'error' => 'attempt_closed']);
}

$was_at_gate = empty($attempt['entered_at']);
$attempt = begin_attempt($conn, $attempt);
$deadline = attempt_deadline_epoch($attempt);

$payload = [
    'ok' => true,
    'started_at' => strtotime($attempt['started_at']) * 1000,
    'deadline' => $deadline !== null ? $deadline * 1000 : null,
];
// The take-quiz page sends no questions while the student is at the start
// gate; they arrive here, once the countdown has started. (On a resume the
// page already has them, with the student's latest answers.)
if ($was_at_gate) {
    [$questions, $options] = quiz_view_data($conn, $attempt);
    $payload['questions_html'] = render_quiz_questions($questions, $options, load_draft($attempt));
}
json_response(200, $payload);
