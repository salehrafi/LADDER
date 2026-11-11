<?php
/**
 * Test bootstrap: a tiny dependency-free test harness, matching the app's
 * "plain PHP, no framework" architecture.
 *
 *   test('name', function () { assert_same(1, 1); });
 *
 * Integration tests get an isolated database via test_db(): it is named
 * "<db_name>_test" and is wiped by reset_test_db(), so real data is never touched.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Tests run from the command line only.\n");
}

require_once __DIR__ . '/../db/config.php';
require_once APP_ROOT . '/includes/helpers.php';
require_once APP_ROOT . '/includes/quiz_helpers.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

final class AssertionFailed extends Exception
{
}

$GLOBALS['__tests'] = [];

function test(string $name, callable $fn): void
{
    $GLOBALS['__tests'][] = [$GLOBALS['__current_file'] ?? '', $name, $fn];
}

function fail(string $message): void
{
    throw new AssertionFailed($message);
}

function assert_same($expected, $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        fail(($message ? "$message\n" : '') . '      expected: ' . var_export($expected, true) . "\n      actual:   " . var_export($actual, true));
    }
}

function assert_true($value, string $message = 'expected true'): void
{
    if ($value !== true) {
        fail($message . ' (got ' . var_export($value, true) . ')');
    }
}

function assert_false($value, string $message = 'expected false'): void
{
    if ($value !== false) {
        fail($message . ' (got ' . var_export($value, true) . ')');
    }
}

function assert_contains(string $needle, string $haystack, string $message = ''): void
{
    if (strpos($haystack, $needle) === false) {
        fail(($message ? "$message: " : '') . "expected to find '$needle' in output (" . strlen($haystack) . ' bytes)');
    }
}

function assert_not_contains(string $needle, string $haystack, string $message = ''): void
{
    if (strpos($haystack, $needle) !== false) {
        fail(($message ? "$message: " : '') . "did not expect to find '$needle'");
    }
}

// ---- Database helpers ---------------------------------------------------------

/** Connection to the isolated test database, created (with schema) on first use. */
function test_db(): mysqli
{
    static $conn = null;
    if ($conn !== null) {
        return $conn;
    }

    $name = env_value('db_name', 'quiz_app') . '_test';
    if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
        throw new RuntimeException("Unsafe test database name: $name");
    }

    $conn = new mysqli(env_value('server_name', 'localhost'), env_value('username', 'root'), env_value('db_password', ''));
    $conn->query("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4");
    $conn->select_db($name);
    $conn->set_charset('utf8mb4');
    $conn->query("SET time_zone = '" . date('P') . "'");

    require APP_ROOT . '/db/schema.php'; // uses $conn
    return $conn;
}

/**
 * Create an account if it does not exist yet (quizzes and attempts must point
 * at a real account: they have foreign keys to reg_info).
 */
function ensure_test_account(mysqli $conn, string $email, string $role): void
{
    $name = ucfirst($role) . ' ' . strtok($email, '@');
    $hash = password_hash('Test@1234', PASSWORD_DEFAULT);
    $stmt = $conn->prepare("INSERT IGNORE INTO reg_info (full_name, email, pass, role) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("ssss", $name, $email, $hash, $role);
    $stmt->execute();
    $stmt->close();
}

/** Empty every app table in the test database. */
function reset_test_db(): void
{
    $conn = test_db();
    $conn->query('SET FOREIGN_KEY_CHECKS = 0');
    foreach (['question_bookmarks', 'notifications', 'grade_audit', 'quiz_assignments', 'attempt_questions', 'attempt_answers', 'quiz_attempts', 'question_options', 'questions', 'quizzes', 'reg_info', 'auth_throttle'] as $table) {
        $conn->query("TRUNCATE TABLE `$table`");
    }
    $conn->query('SET FOREIGN_KEY_CHECKS = 1');
}

/**
 * Create a quiz with questions. Each question:
 *   ['mcq', 'text', points, null, ['A' => false, 'B' => true]]
 *   ['short_answer', 'text', points, 'model answer or null', []]
 *
 * @return array{quiz_id:int, questions:array<int,array{id:int, options:array<string,int>, correct:?int}>}
 */
function make_quiz(mysqli $conn, array $questions, array $settings = []): array
{
    $s = $settings + ['teacher' => 'teacher@test.local', 'title' => 'Test quiz', 'status' => 'published', 'max_attempts' => 1, 'time_limit' => null];
    ensure_test_account($conn, $s['teacher'], 'teacher');

    $stmt = $conn->prepare("INSERT INTO quizzes (teacher_email, title, status, max_attempts, time_limit_minutes) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("sssii", $s['teacher'], $s['title'], $s['status'], $s['max_attempts'], $s['time_limit']);
    $stmt->execute();
    $quiz_id = (int) $stmt->insert_id;
    $stmt->close();

    $out = ['quiz_id' => $quiz_id, 'questions' => []];
    foreach ($questions as $i => [$type, $text, $points, $model, $options]) {
        $q = $conn->prepare("INSERT INTO questions (quiz_id, type, question_text, points, order_index, model_answer) VALUES (?, ?, ?, ?, ?, ?)");
        $q->bind_param("issdis", $quiz_id, $type, $text, $points, $i, $model);
        $q->execute();
        $qid = (int) $q->insert_id;
        $q->close();

        $entry = ['id' => $qid, 'options' => [], 'correct' => null];
        $oi = 0;
        foreach ($options as $otext => $correct) {
            $o = $conn->prepare("INSERT INTO question_options (question_id, option_text, is_correct, order_index) VALUES (?, ?, ?, ?)");
            $c = $correct ? 1 : 0;
            $o->bind_param("isii", $qid, $otext, $c, $oi);
            $o->execute();
            $entry['options'][$otext] = (int) $o->insert_id;
            if ($correct) {
                $entry['correct'] = (int) $o->insert_id;
            }
            $o->close();
            $oi++;
        }
        $out['questions'][] = $entry;
    }
    return $out;
}

/** Start an in-progress attempt for a student and return the row. */
function make_attempt(mysqli $conn, array $quiz, string $student = 'student@test.local', ?int $time_limit = null, int $started_minutes_ago = 0): array
{
    ensure_test_account($conn, $student, 'student');
    $order = json_encode(array_column($quiz['questions'], 'id'));
    $stmt = $conn->prepare(
        "INSERT INTO quiz_attempts (quiz_id, student_email, attempt_number, question_order, option_order, time_limit_minutes, started_at, status)
         VALUES (?, ?, 1, ?, '{}', ?, DATE_SUB(NOW(), INTERVAL ? MINUTE), 'in_progress')"
    );
    $stmt->bind_param("issii", $quiz['quiz_id'], $student, $order, $time_limit, $started_minutes_ago);
    $stmt->execute();
    $id = (int) $stmt->insert_id;
    $stmt->close();

    return $conn->query("SELECT * FROM quiz_attempts WHERE id = $id")->fetch_assoc();
}
