<?php
// Download one quiz's submitted attempts as a CSV file (opens in Excel / Sheets).
require_once __DIR__ . '/../../includes/bootstrap.php';

require_role('teacher', '../../auth/login.php');

$teacher_email = current_user_email();
$quiz_id = (int) ($_GET['quiz_id'] ?? 0);

$stmt = $conn->prepare("SELECT id, title FROM quizzes WHERE id = ? AND teacher_email = ?");
$stmt->bind_param("is", $quiz_id, $teacher_email);
$stmt->execute();
$quiz = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$quiz) {
    header("Location: results.php");
    exit;
}

$stmt = $conn->prepare(
    "SELECT qa.attempt_number, qa.submitted_at, qa.total_score, qa.max_score, qa.needs_manual_grading,
            qa.student_email, COALESCE(r.full_name, qa.student_email) AS student_name, r.id AS student_id
     FROM quiz_attempts qa
     LEFT JOIN reg_info r ON r.email = qa.student_email
     WHERE qa.quiz_id = ? AND qa.status = 'submitted' AND qa.is_practice = 0
     ORDER BY student_name ASC, qa.attempt_number ASC"
);
$stmt->bind_param("i", $quiz_id);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$sections_by_student = section_names_by_student($conn, $teacher_email);
$csv = [];
foreach ($rows as $r) {
    $max = (float) $r['max_score'];
    $score = (float) $r['total_score'];
    $csv[] = [
        $r['student_name'],
        $r['student_email'],
        $r['student_id'] ?? '',
        $sections_by_student[$r['student_email']] ?? '',
        $r['attempt_number'],
        $r['submitted_at'],
        fmt_score($score),
        fmt_score($max),
        $max > 0 ? round($score / $max * 100, 1) . '%' : '',
        $r['needs_manual_grading'] ? 'Pending' : 'Graded',
    ];
}
send_csv($quiz['title'] . '_results.csv',
    ['Student', 'Email', 'Student ID', 'Section', 'Attempt', 'Submitted', 'Score', 'Max', 'Percent', 'Status'], $csv);
