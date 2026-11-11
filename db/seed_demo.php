<?php
/**
 * Demo data seeder. Command line only:
 *
 *     php db/seed_demo.php           create demo data (replaces previous demo data)
 *     php db/seed_demo.php --reset   remove demo data only
 *
 * Everything it creates uses @demo.test addresses, so it never touches real
 * accounts. Do not run this against a live site with real student data.
 *
 * Logins: teacher@demo.test and student01..12@demo.test, password Demo@1234.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Run this from the command line: php db/seed_demo.php\n");
}

require_once __DIR__ . '/conn.php';
require_once __DIR__ . '/../includes/quiz_helpers.php';
require_once __DIR__ . '/../includes/class_helpers.php';

const DEMO_PASSWORD = 'Demo@1234';
const DEMO_TEACHER = 'teacher@demo.test';
const DEMO_DOMAIN = '%@demo.test';

$reset_only = in_array('--reset', $argv ?? [], true);

// ---- 1. Remove previous demo data -------------------------------------------
// Quizzes cascade to questions, options and attempts. Accounts that still own
// content cannot be deleted (foreign keys), so bank questions, subjects and
// any attempts on other teachers' quizzes go first.
$demo = $conn->real_escape_string(DEMO_DOMAIN);
$conn->begin_transaction();
$conn->query("DELETE FROM quiz_attempts WHERE student_email LIKE '$demo'");
$conn->query("DELETE FROM quizzes WHERE teacher_email LIKE '" . $conn->real_escape_string(DEMO_DOMAIN) . "'");
$conn->query("DELETE FROM questions WHERE teacher_email LIKE '$demo'");
$conn->query("DELETE FROM subjects WHERE teacher_email LIKE '$demo'");
$conn->query("DELETE FROM sections WHERE teacher_email LIKE '" . $conn->real_escape_string(DEMO_DOMAIN) . "'");
$conn->query("DELETE FROM section_students WHERE student_email LIKE '" . $conn->real_escape_string(DEMO_DOMAIN) . "'");
$conn->query("DELETE FROM reg_info WHERE email LIKE '" . $conn->real_escape_string(DEMO_DOMAIN) . "'");
$conn->commit();

if ($reset_only) {
    echo "Demo data removed.\n";
    exit(0);
}

// ---- 2. Accounts --------------------------------------------------------------
$first_names = ['Rafi', 'Nusrat', 'Tanvir', 'Sadia', 'Imran', 'Farhana', 'Arif', 'Mim', 'Shuvo', 'Tasnim', 'Hasan', 'Riya'];
$last_names = ['Ahmed', 'Khan', 'Hossain', 'Rahman', 'Islam', 'Chowdhury', 'Uddin', 'Sultana', 'Karim', 'Begum', 'Mia', 'Akter'];

$hash = password_hash(DEMO_PASSWORD, PASSWORD_DEFAULT);

$insert_user = $conn->prepare("INSERT INTO reg_info (id, full_name, email, pass, role) VALUES (?, ?, ?, ?, ?)");

$teacher_role = 'teacher';
$teacher_id = 'T1001';
$teacher_name = 'Demo Teacher';
$teacher_email = DEMO_TEACHER;
$insert_user->bind_param("sssss", $teacher_id, $teacher_name, $teacher_email, $hash, $teacher_role);
$insert_user->execute();

$students = [];
for ($i = 0; $i < 12; $i++) {
    $sid = 'S' . str_pad((string) (2001 + $i), 4, '0', STR_PAD_LEFT);
    $name = $first_names[$i] . ' ' . $last_names[$i];
    $email = sprintf('student%02d@demo.test', $i + 1);
    $role = 'student';
    $insert_user->bind_param("sssss", $sid, $name, $email, $hash, $role);
    $insert_user->execute();
    $students[] = $email;
}
$insert_user->close();

// Two class sections for the demo teacher: students 1-6 in A, 7-12 in B.
$section_ids = [
    'A' => ensure_section($conn, DEMO_TEACHER, 'CSE-101 Section A'),
    'B' => ensure_section($conn, DEMO_TEACHER, 'CSE-101 Section B'),
];
$enrol = $conn->prepare("INSERT IGNORE INTO section_students (section_id, student_email) VALUES (?, ?)");
foreach ($students as $i => $email) {
    $sec = $i < 6 ? $section_ids['A'] : $section_ids['B'];
    $enrol->bind_param("is", $sec, $email);
    $enrol->execute();
}
$enrol->close();

// ---- 3. Quizzes ---------------------------------------------------------------
// Each quiz: [title, description, time limit, max attempts, status, questions]
// A question: [type, text, points, model answer or null, options (text => correct?)]
$quizzes = [
    [
        'Chapter 3: Photosynthesis Quiz',
        'Covers light reactions and the Calvin cycle. Read each question carefully.',
        15, 1, 'published',
        [
            ['mcq', 'Where do the light-dependent reactions take place?', 1, null, [
                'Stroma' => false, 'Thylakoid membrane' => true, 'Cytoplasm' => false, 'Nucleus' => false]],
            ['mcq', 'Which molecule is the primary electron acceptor in photosystem II?', 1, null, [
                'NADP+' => false, 'Plastoquinone' => true, 'ATP' => false, 'Ferredoxin' => false]],
            ['mcq', 'The Calvin cycle uses ATP and which other product of the light reactions?', 1, null, [
                'Oxygen' => false, 'Glucose' => false, 'NADPH' => true, 'Carbon dioxide' => false]],
            ['mcq', 'What enzyme fixes carbon dioxide in the Calvin cycle?', 1, null, [
                'Rubisco' => true, 'Hexokinase' => false, 'Lipase' => false, 'Amylase' => false]],
            ['mcq', 'Which pigment absorbs most strongly in the red and blue regions?', 1, null, [
                'Carotene' => false, 'Chlorophyll a' => true, 'Xanthophyll' => false, 'Anthocyanin' => false]],
        ],
    ],
    [
        'Short Answer: Cell Biology',
        'Answer in one or two sentences. Your teacher will review these by hand.',
        20, 2, 'published',
        [
            ['short_answer', 'In one sentence, what is the function of mitochondria?', 2, null, []],
            ['short_answer', 'Why do plant cells have a cell wall?', 2, null, []],
            ['short_answer', 'Name the process by which a cell divides to form two identical daughter cells.', 2, 'mitosis', []],
        ],
    ],
    [
        'Mid-term Practice (Draft)',
        'Work in progress. Not visible to students yet.',
        null, 0, 'draft',
        [
            ['mcq', 'Which organelle is known as the powerhouse of the cell?', 1, null, [
                'Mitochondrion' => true, 'Golgi body' => false, 'Ribosome' => false]],
        ],
    ],
];

$insert_quiz = $conn->prepare(
    "INSERT INTO quizzes (teacher_email, title, description, time_limit_minutes, shuffle_questions, shuffle_options, max_attempts, status)
     VALUES (?, ?, ?, ?, 1, 0, ?, ?)"
);
$insert_question = $conn->prepare(
    "INSERT INTO questions (quiz_id, teacher_email, type, question_text, points, order_index, model_answer) VALUES (?, '" . DEMO_TEACHER . "', ?, ?, ?, ?, ?)"
);
$insert_option = $conn->prepare(
    "INSERT INTO question_options (question_id, option_text, is_correct, order_index) VALUES (?, ?, ?, ?)"
);

$quiz_ids = [];
$conn->begin_transaction();
foreach ($quizzes as $qi => [$title, $desc, $limit, $max_attempts, $status, $questions]) {
    $quiz_teacher = DEMO_TEACHER;
    $insert_quiz->bind_param("sssiis", $quiz_teacher, $title, $desc, $limit, $max_attempts, $status);
    $insert_quiz->execute();
    $quiz_id = (int) $insert_quiz->insert_id;
    $quiz_ids[$qi] = $quiz_id;
    // Demo quizzes are for both demo sections.
    set_quiz_sections($conn, DEMO_TEACHER, $quiz_id, array_values($section_ids));

    foreach ($questions as $oi => [$type, $text, $points, $model, $options]) {
        $order = $oi + 1;
        $insert_question->bind_param("issdis", $quiz_id, $type, $text, $points, $order, $model);
        $insert_question->execute();
        $qid = (int) $insert_question->insert_id;

        $oo = 1;
        foreach ($options as $otext => $correct) {
            $is_correct = $correct ? 1 : 0;
            $insert_option->bind_param("isii", $qid, $otext, $is_correct, $oo);
            $insert_option->execute();
            $oo++;
        }
    }
}
$conn->commit();

// ---- 4. Attempts -------------------------------------------------------------
// Real grading is used (finalize_attempt), so scores match what the app
// would produce. Randomness gives a realistic spread of results.
mt_srand(42); // fixed seed: the same demo data every run

$short_answers = [
    'It produces most of the cell\'s ATP through respiration.',
    'Cell walls give structure and protect against bursting.',
    'mitosis',
    'It makes energy.',
    'Cell division',
    'To support the plant and stop it from bursting.',
];

function demo_random_answers(mysqli $conn, array $attempt, array $short_answers, float $skill): array
{
    $answers = [];
    $qorder = json_decode($attempt['question_order'], true) ?: [];
    foreach ($qorder as $qid) {
        $qid = (int) $qid;
        $type = null;
        $r = $conn->query("SELECT type FROM questions WHERE id = $qid")->fetch_assoc();
        $type = $r['type'];
        if (mt_rand(1, 100) > 85) {
            continue; // skipped question
        }
        if ($type === 'mcq') {
            $res = $conn->query("SELECT id, is_correct FROM question_options WHERE question_id = $qid");
            $opts = $res->fetch_all(MYSQLI_ASSOC);
            $correct = array_values(array_filter($opts, fn($o) => $o['is_correct']));
            $wrong = array_values(array_filter($opts, fn($o) => !$o['is_correct']));
            $pick = (mt_rand(1, 100) / 100 <= $skill && $correct) ? $correct[0] : ($wrong[array_rand($wrong)] ?? $opts[0]);
            $answers["q_$qid"] = (string) $pick['id'];
        } else {
            $answers["q_$qid"] = $short_answers[array_rand($short_answers)];
        }
    }
    return $answers;
}

$insert_attempt = $conn->prepare(
    "INSERT INTO quiz_attempts (quiz_id, student_email, attempt_number, question_order, option_order, time_limit_minutes, started_at, status)
     VALUES (?, ?, ?, ?, '{}', ?, DATE_SUB(NOW(), INTERVAL ? MINUTE), 'in_progress')"
);

$published = [$quiz_ids[0], $quiz_ids[1]];
// No outer transaction here: finalize_attempt() runs its own transaction per
// attempt (MySQL has no nested transactions). A failed run is cleaned up by
// re-running the seeder, which deletes previous demo data first.
foreach ($students as $si => $email) {
    // Vary how strong each student is: 0.35 .. 0.95 chance to get MCQ right.
    $skill = 0.35 + (($si * 37) % 60) / 100;

    foreach ($published as $qi => $quiz_id) {
        // Not every student takes every quiz.
        if (mt_rand(1, 100) > 85) {
            continue;
        }

        $qrow = $conn->query("SELECT * FROM quizzes WHERE id = $quiz_id")->fetch_assoc();
        $qids = array_map('intval', array_column(
            $conn->query("SELECT id FROM questions WHERE quiz_id = $quiz_id ORDER BY order_index")->fetch_all(MYSQLI_ASSOC),
            'id'
        ));
        $qorder = json_encode($qids);
        $limit = $qrow['time_limit_minutes'];
        $minutes_ago = mt_rand(60, 60 * 24 * 10);
        $attempt_number = 1;

        $insert_attempt->bind_param("isisii", $quiz_id, $email, $attempt_number, $qorder, $limit, $minutes_ago);
        $insert_attempt->execute();
        $attempt_id = (int) $insert_attempt->insert_id;

        $attempt = $conn->query("SELECT * FROM quiz_attempts WHERE id = $attempt_id")->fetch_assoc();
        $answers = demo_random_answers($conn, $attempt, $short_answers, $skill);
        finalize_attempt($conn, $attempt, $answers);
    }
}

// One student is part-way through quiz 1, so the Resume flow has demo data too.
$resume_quiz = $published[0];
$resume_email = $students[0];
$qids = array_map('intval', array_column(
    $conn->query("SELECT id FROM questions WHERE quiz_id = $resume_quiz ORDER BY order_index")->fetch_all(MYSQLI_ASSOC),
    'id'
));
$resume_qorder = json_encode($qids);
$resume_number = 2;
$resume_limit = 15;
$resume_minutes = 3;
$insert_attempt->bind_param("isisii", $resume_quiz, $resume_email, $resume_number, $resume_qorder, $resume_limit, $resume_minutes);
$insert_attempt->execute();
$resume_attempt_id = (int) $insert_attempt->insert_id;

$first_q = $qids[0];
$draft = json_encode(["q_$first_q" => (string) $conn->query("SELECT id FROM question_options WHERE question_id = $first_q AND is_correct = 1")->fetch_assoc()['id']]);
$conn->query("UPDATE quiz_attempts SET draft_answers = '" . $conn->real_escape_string($draft) . "' WHERE id = $resume_attempt_id");

echo "Demo data created.\n";
echo "  Teacher login:  " . DEMO_TEACHER . "  /  " . DEMO_PASSWORD . "\n";
echo "  Student logins: student01@demo.test ... student12@demo.test  /  " . DEMO_PASSWORD . "\n";
echo "  Quizzes: 2 published (1 MCQ, 1 short answer needing grading), 1 draft.\n";
