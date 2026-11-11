<?php
/**
 * Question bank: each teacher's reusable questions, subjects and topics, and
 * the shared create/edit logic used by the bank and by a quiz's question page.
 *
 * A question with quiz_id NULL is a bank question. Adding it to a quiz makes
 * a copy (source_question_id), so editing the bank never changes a quiz that
 * already uses it, and editing a quiz question never changes the bank.
 * Ownership is always checked through questions.teacher_email.
 */

const QUESTION_TEXT_MAX = 5000;
const OPTION_TEXT_MAX = 500;
const EXPLANATION_MAX = 5000;
const CATALOG_NAME_MAX = 80;
const BANK_PAGE_SIZE = 20;

// ---- Subjects and topics ------------------------------------------------------

/** @return array<int,array> the teacher's subjects, each with 'topics' => [[id, name, question_count]] */
function teacher_catalog(mysqli $conn, string $teacher_email): array
{
    $stmt = $conn->prepare("SELECT id, name FROM subjects WHERE teacher_email = ? ORDER BY name");
    $stmt->bind_param("s", $teacher_email);
    $stmt->execute();
    $subjects = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $s) {
        $subjects[(int) $s['id']] = ['id' => (int) $s['id'], 'name' => $s['name'], 'topics' => []];
    }
    $stmt->close();
    if (!$subjects) {
        return [];
    }
    $stmt = $conn->prepare(
        "SELECT t.id, t.subject_id, t.name,
                (SELECT COUNT(*) FROM questions q WHERE q.topic_id = t.id AND q.deleted_at IS NULL) AS question_count
         FROM topics t JOIN subjects s ON s.id = t.subject_id
         WHERE s.teacher_email = ? ORDER BY t.name"
    );
    $stmt->bind_param("s", $teacher_email);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $t) {
        $subjects[(int) $t['subject_id']]['topics'][] = ['id' => (int) $t['id'], 'name' => $t['name'], 'question_count' => (int) $t['question_count']];
    }
    $stmt->close();
    return $subjects;
}

/** Does this topic belong to one of the teacher's subjects? */
function teacher_owns_topic(mysqli $conn, string $teacher_email, int $topic_id): bool
{
    $stmt = $conn->prepare("SELECT 1 FROM topics t JOIN subjects s ON s.id = t.subject_id WHERE t.id = ? AND s.teacher_email = ?");
    $stmt->bind_param("is", $topic_id, $teacher_email);
    $stmt->execute();
    $ok = $stmt->get_result()->num_rows === 1;
    $stmt->close();
    return $ok;
}

function teacher_owns_subject(mysqli $conn, string $teacher_email, int $subject_id): bool
{
    $stmt = $conn->prepare("SELECT 1 FROM subjects WHERE id = ? AND teacher_email = ?");
    $stmt->bind_param("is", $subject_id, $teacher_email);
    $stmt->execute();
    $ok = $stmt->get_result()->num_rows === 1;
    $stmt->close();
    return $ok;
}

function clean_catalog_name(string $name): string
{
    $name = trim(preg_replace('/\s+/u', ' ', $name));
    if ($name === '' || mb_strlen($name) > CATALOG_NAME_MAX) {
        throw new InvalidArgumentException('Names must be 1-' . CATALOG_NAME_MAX . ' characters.');
    }
    return $name;
}

/** Create a subject (or return the existing one with that name). */
function create_subject(mysqli $conn, string $teacher_email, string $name): int
{
    $name = clean_catalog_name($name);
    $stmt = $conn->prepare("SELECT id FROM subjects WHERE teacher_email = ? AND name = ?");
    $stmt->bind_param("ss", $teacher_email, $name);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) {
        return (int) $row['id'];
    }
    $stmt = $conn->prepare("INSERT INTO subjects (teacher_email, name) VALUES (?, ?)");
    $stmt->bind_param("ss", $teacher_email, $name);
    $stmt->execute();
    $id = (int) $stmt->insert_id;
    $stmt->close();
    return $id;
}

/** Create a topic under one of the teacher's subjects (or return the existing one). */
function create_topic(mysqli $conn, string $teacher_email, int $subject_id, string $name): int
{
    if (!teacher_owns_subject($conn, $teacher_email, $subject_id)) {
        throw new InvalidArgumentException('Choose one of your subjects.');
    }
    $name = clean_catalog_name($name);
    $stmt = $conn->prepare("SELECT id FROM topics WHERE subject_id = ? AND name = ?");
    $stmt->bind_param("is", $subject_id, $name);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) {
        return (int) $row['id'];
    }
    $stmt = $conn->prepare("INSERT INTO topics (subject_id, name) VALUES (?, ?)");
    $stmt->bind_param("is", $subject_id, $name);
    $stmt->execute();
    $id = (int) $stmt->insert_id;
    $stmt->close();
    return $id;
}

/** Delete a subject (its topics go with it; questions keep existing, without a topic). */
function delete_subject(mysqli $conn, string $teacher_email, int $subject_id): bool
{
    $stmt = $conn->prepare("DELETE FROM subjects WHERE id = ? AND teacher_email = ?");
    $stmt->bind_param("is", $subject_id, $teacher_email);
    $stmt->execute();
    $ok = $stmt->affected_rows === 1;
    $stmt->close();
    return $ok;
}

function delete_topic(mysqli $conn, string $teacher_email, int $topic_id): bool
{
    if (!teacher_owns_topic($conn, $teacher_email, $topic_id)) {
        return false;
    }
    $stmt = $conn->prepare("DELETE FROM topics WHERE id = ?");
    $stmt->bind_param("i", $topic_id);
    $stmt->execute();
    $ok = $stmt->affected_rows === 1;
    $stmt->close();
    return $ok;
}

// ---- The question form ---------------------------------------------------------------

/** Empty form values for a new question. */
function question_form_defaults(): array
{
    return [
        'id' => 0, 'type' => 'mcq', 'question_text' => '', 'points' => '1', 'difficulty' => '', 'topic_id' => 0,
        'explanation' => '', 'options' => [['text' => '', 'correct' => true], ['text' => '', 'correct' => false], ['text' => '', 'correct' => false], ['text' => '', 'correct' => false]],
        'tf_answer' => 'true', 'accepted' => '', 'case_sensitive' => false, 'ignore_punctuation' => true,
        'numeric_value' => '', 'tolerance' => '0', 'tolerance_type' => 'absolute', 'blank_answers' => '',
        'partial' => true, 'scoring' => 'all_or_nothing', 'guidance' => '',
    ];
}

/** Form values for editing an existing question (see find_teacher_question()). */
function question_form_values(array $q): array
{
    $config = question_config($q);
    $v = question_form_defaults();
    $v['id'] = (int) $q['id'];
    $v['type'] = $q['type'];
    $v['question_text'] = $q['question_text'];
    $v['points'] = fmt_score($q['points']);
    $v['difficulty'] = (string) ($q['difficulty'] ?? '');
    $v['topic_id'] = (int) ($q['topic_id'] ?? 0);
    $v['explanation'] = (string) ($q['explanation'] ?? '');
    if (in_array($q['type'], OPTION_TYPES, true)) {
        $v['options'] = array_map(fn($o) => ['text' => $o['option_text'], 'correct' => (int) $o['is_correct'] === 1], $q['options']);
        if ($q['type'] === 'true_false') {
            $true = $q['options'][0] ?? null;
            $v['tf_answer'] = ($true && (int) $true['is_correct'] === 1) ? 'true' : 'false';
        }
    }
    switch ($q['type']) {
        case 'short_answer':
            $v['accepted'] = implode("\n", $config['accepted']);
            $v['case_sensitive'] = $config['case_sensitive'];
            $v['ignore_punctuation'] = $config['ignore_punctuation'];
            break;
        case 'numeric':
            $v['numeric_value'] = $config['value'] === null ? '' : rtrim(rtrim(number_format($config['value'], 6, '.', ''), '0'), '.');
            $v['tolerance'] = rtrim(rtrim(number_format($config['tolerance'], 6, '.', ''), '0'), '.');
            $v['tolerance_type'] = $config['tolerance_type'];
            break;
        case 'fill_blank':
            $v['blank_answers'] = implode("\n", array_map(fn($alts) => implode(' | ', $alts), $config['blanks']));
            $v['case_sensitive'] = $config['case_sensitive'];
            $v['ignore_punctuation'] = $config['ignore_punctuation'];
            $v['partial'] = $config['partial'];
            break;
        case 'mcq_multi':
            $v['scoring'] = $config['scoring'];
            break;
        case 'essay':
            $v['guidance'] = $config['guidance'];
            break;
    }
    return $v;
}

/** Split a "one per line" textarea into trimmed non-empty lines. */
function form_lines(string $text): array
{
    return array_values(array_filter(array_map('trim', preg_split('/\R/u', $text)), fn($l) => $l !== ''));
}

/**
 * Validate the submitted question form.
 *
 * Field names stay compatible with the original editor (options[],
 * correct_option, model_answer), so older forms and scripts keep working.
 *
 * @return array{0: ?array, 1: list<string>, 2: array} [question data or null, errors, form values to re-show]
 */
function question_from_form(mysqli $conn, string $teacher_email, array $post): array
{
    $errors = [];
    $v = question_form_defaults();
    $type = (string) ($post['type'] ?? 'mcq');
    $v['type'] = isset(QUESTION_TYPES[$type]) ? $type : 'mcq';
    $type = $v['type'];
    $v['question_text'] = trim((string) ($post['question_text'] ?? ''));
    $v['points'] = trim((string) ($post['points'] ?? '1'));
    $v['difficulty'] = isset(DIFFICULTIES[$post['difficulty'] ?? '']) ? $post['difficulty'] : '';
    $v['topic_id'] = (int) ($post['topic_id'] ?? 0);
    $v['explanation'] = trim((string) ($post['explanation'] ?? ''));

    if ($v['question_text'] === '') {
        $errors[] = 'Question text is required.';
    } elseif (mb_strlen($v['question_text']) > QUESTION_TEXT_MAX) {
        $errors[] = 'Question text must be ' . QUESTION_TEXT_MAX . ' characters or fewer.';
    }
    $points = is_numeric($v['points']) ? round((float) $v['points'], 2) : 0;
    if ($points < 0.25 || $points > 999.99) {
        $errors[] = 'Points must be between 0.25 and 999.99.';
    }
    if (mb_strlen($v['explanation']) > EXPLANATION_MAX) {
        $errors[] = 'The explanation must be ' . EXPLANATION_MAX . ' characters or fewer.';
    }
    if ($v['topic_id'] && !teacher_owns_topic($conn, $teacher_email, $v['topic_id'])) {
        $errors[] = 'Choose one of your topics.';
        $v['topic_id'] = 0;
    }

    $options = [];
    $config = [];
    $model_answer = null;

    if ($type === 'true_false') {
        $v['tf_answer'] = ($post['tf_answer'] ?? 'true') === 'false' ? 'false' : 'true';
        $options = [['text' => 'True', 'correct' => $v['tf_answer'] === 'true'], ['text' => 'False', 'correct' => $v['tf_answer'] === 'false']];
    } elseif ($type === 'mcq' || $type === 'mcq_multi') {
        $texts = array_map(fn($t) => trim((string) $t), is_array($post['options'] ?? null) ? $post['options'] : []);
        $correct_single = isset($post['correct_option']) ? (int) $post['correct_option'] : -1;
        $correct_multi = array_map('intval', is_array($post['correct'] ?? null) ? $post['correct'] : []);
        $v['options'] = [];
        foreach ($texts as $i => $t) {
            $is = $type === 'mcq' ? $i === $correct_single : in_array($i, $correct_multi, true);
            $v['options'][] = ['text' => $t, 'correct' => $is];
            if ($t !== '') {
                if (mb_strlen($t) > OPTION_TEXT_MAX) {
                    $errors[] = 'Each option must be ' . OPTION_TEXT_MAX . ' characters or fewer.';
                }
                $options[] = ['text' => $t, 'correct' => $is];
            } elseif ($is) {
                $errors[] = 'An empty option cannot be the correct one.';
            }
        }
        $right = count(array_filter($options, fn($o) => $o['correct']));
        if (count($options) < 2) {
            $errors[] = 'Add at least 2 options.';
        } elseif ($type === 'mcq' && $right !== 1) {
            $errors[] = 'Select which option is correct.';
        } elseif ($type === 'mcq_multi' && $right < 1) {
            $errors[] = 'Tick at least one correct option.';
        }
        if ($type === 'mcq_multi') {
            $v['scoring'] = isset(MULTI_SCORING[$post['scoring'] ?? '']) ? $post['scoring'] : 'all_or_nothing';
            $config = ['scoring' => $v['scoring']];
        }
    } elseif ($type === 'short_answer') {
        // "accepted" (one per line); older forms send a single model_answer.
        $v['accepted'] = (string) ($post['accepted'] ?? ($post['model_answer'] ?? ''));
        $v['case_sensitive'] = !empty($post['case_sensitive']);
        $v['ignore_punctuation'] = isset($post['accepted']) ? !empty($post['ignore_punctuation']) : false;
        $accepted = form_lines($v['accepted']);
        $config = ['accepted' => $accepted, 'case_sensitive' => $v['case_sensitive'], 'ignore_punctuation' => $v['ignore_punctuation']];
        $model_answer = $accepted[0] ?? null;
    } elseif ($type === 'numeric') {
        $v['numeric_value'] = trim((string) ($post['numeric_value'] ?? ''));
        $v['tolerance'] = trim((string) ($post['tolerance'] ?? '0'));
        $v['tolerance_type'] = ($post['tolerance_type'] ?? '') === 'percent' ? 'percent' : 'absolute';
        $value = parse_number($v['numeric_value']);
        $tolerance = $v['tolerance'] === '' ? 0.0 : parse_number($v['tolerance']);
        if ($value === null) {
            $errors[] = 'Enter the correct numerical answer.';
        }
        if ($tolerance === null || $tolerance < 0) {
            $errors[] = 'The tolerance must be a number of 0 or more.';
        }
        $config = ['value' => $value, 'tolerance' => (float) $tolerance, 'tolerance_type' => $v['tolerance_type']];
        $model_answer = $value === null ? null : (string) $value;
    } elseif ($type === 'fill_blank') {
        $v['blank_answers'] = (string) ($post['blank_answers'] ?? '');
        $v['case_sensitive'] = !empty($post['case_sensitive']);
        $v['ignore_punctuation'] = !empty($post['ignore_punctuation']);
        $v['partial'] = !empty($post['partial']);
        $blanks = array_map(fn($line) => array_values(array_filter(array_map('trim', explode('|', $line)), fn($a) => $a !== '')), form_lines($v['blank_answers']));
        $count = count_blanks($v['question_text']);
        if ($count === 0) {
            $errors[] = 'Mark each blank in the question with three underscores: ___';
        } elseif (count($blanks) !== $count) {
            $errors[] = "The question has $count blank(s); give one line of accepted answers per blank (found " . count($blanks) . ').';
        }
        $config = ['blanks' => $blanks, 'case_sensitive' => $v['case_sensitive'], 'ignore_punctuation' => $v['ignore_punctuation'], 'partial' => $v['partial']];
    } elseif ($type === 'essay') {
        $v['guidance'] = trim((string) ($post['guidance'] ?? ''));
        if (mb_strlen($v['guidance']) > EXPLANATION_MAX) {
            $errors[] = 'Marking guidance must be ' . EXPLANATION_MAX . ' characters or fewer.';
        }
        $config = ['guidance' => $v['guidance']];
    }
    if ($type === 'true_false') {
        $v['options'] = $options;
    }

    if ($errors) {
        return [null, $errors, $v];
    }
    return [[
        'type' => $type,
        'question_text' => $v['question_text'],
        'points' => $points,
        'difficulty' => $v['difficulty'] === '' ? null : $v['difficulty'],
        'topic_id' => $v['topic_id'] ?: null,
        'explanation' => $v['explanation'] === '' ? null : $v['explanation'],
        'options' => $options,
        'answer_config' => $config ? json_encode($config, JSON_UNESCAPED_UNICODE) : null,
        'model_answer' => $model_answer,
    ], [], $v];
}

// ---- Saving and loading -----------------------------------------------------------------

/**
 * Create or update a question. For a quiz question pass $quiz_id (the caller
 * has checked the quiz is the teacher's); for a bank question pass null.
 * Options are updated in place by position, so their ids stay stable for
 * attempts and re-grading.
 *
 * @return int the question id
 */
function save_question(mysqli $conn, string $teacher_email, array $data, ?int $question_id = null, ?int $quiz_id = null): int
{
    $conn->begin_transaction();
    try {
        if ($question_id) {
            $stmt = $conn->prepare(
                "UPDATE questions SET type = ?, question_text = ?, points = ?, difficulty = ?, topic_id = ?, explanation = ?,
                        answer_config = ?, model_answer = ?
                 WHERE id = ? AND teacher_email = ? AND deleted_at IS NULL"
            );
            $stmt->bind_param("ssdsisssis", $data['type'], $data['question_text'], $data['points'], $data['difficulty'], $data['topic_id'],
                $data['explanation'], $data['answer_config'], $data['model_answer'], $question_id, $teacher_email);
            $stmt->execute();
            $stmt->close();
        } else {
            $order = 0;
            if ($quiz_id !== null) {
                $stmt = $conn->prepare("SELECT COALESCE(MAX(order_index), -1) + 1 AS n FROM questions WHERE quiz_id = ?");
                $stmt->bind_param("i", $quiz_id);
                $stmt->execute();
                $order = (int) $stmt->get_result()->fetch_assoc()['n'];
                $stmt->close();
            }
            $stmt = $conn->prepare(
                "INSERT INTO questions (quiz_id, teacher_email, type, question_text, points, order_index, difficulty, topic_id, explanation, answer_config, model_answer)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param("isssdisisss", $quiz_id, $teacher_email, $data['type'], $data['question_text'], $data['points'], $order,
                $data['difficulty'], $data['topic_id'], $data['explanation'], $data['answer_config'], $data['model_answer']);
            $stmt->execute();
            $question_id = (int) $stmt->insert_id;
            $stmt->close();
        }
        replace_question_options($conn, $question_id, $data['options']);
        $conn->commit();
        return $question_id;
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

/** Write a question's options by position: update, add, remove the surplus. */
function replace_question_options(mysqli $conn, int $question_id, array $options): void
{
    $stmt = $conn->prepare("SELECT id FROM question_options WHERE question_id = ? ORDER BY order_index, id");
    $stmt->bind_param("i", $question_id);
    $stmt->execute();
    $existing = array_map('intval', array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'id'));
    $stmt->close();

    $upd = $conn->prepare("UPDATE question_options SET option_text = ?, is_correct = ?, order_index = ? WHERE id = ?");
    $ins = $conn->prepare("INSERT INTO question_options (question_id, option_text, is_correct, order_index) VALUES (?, ?, ?, ?)");
    foreach (array_values($options) as $i => $o) {
        $correct = !empty($o['correct']) ? 1 : 0;
        if (isset($existing[$i])) {
            $upd->bind_param("siii", $o['text'], $correct, $i, $existing[$i]);
            $upd->execute();
        } else {
            $ins->bind_param("isii", $question_id, $o['text'], $correct, $i);
            $ins->execute();
        }
    }
    $upd->close();
    $ins->close();
    $surplus = array_slice($existing, count($options));
    if ($surplus) {
        $conn->query("DELETE FROM question_options WHERE id IN (" . implode(',', $surplus) . ")");
    }
}

/** A live question of this teacher, with options, or null. */
function find_teacher_question(mysqli $conn, string $teacher_email, int $question_id): ?array
{
    $stmt = $conn->prepare(
        "SELECT q.*, t.name AS topic_name, s.name AS subject_name FROM questions q
         LEFT JOIN topics t ON t.id = q.topic_id LEFT JOIN subjects s ON s.id = t.subject_id
         WHERE q.id = ? AND q.teacher_email = ? AND q.deleted_at IS NULL"
    );
    $stmt->bind_param("is", $question_id, $teacher_email);
    $stmt->execute();
    $q = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$q) {
        return null;
    }
    return attach_options($conn, [$q])[0];
}

/**
 * Copy a question: into a quiz ($quiz_id) or into the bank (null). The copy
 * remembers its source.
 *
 * @return int new question id
 */
function copy_question(mysqli $conn, string $teacher_email, int $question_id, ?int $quiz_id): int
{
    $q = find_teacher_question($conn, $teacher_email, $question_id);
    if (!$q) {
        throw new InvalidArgumentException('Question not found.');
    }
    $data = [
        'type' => $q['type'],
        'question_text' => $q['question_text'],
        'points' => (float) $q['points'],
        'difficulty' => $q['difficulty'],
        'topic_id' => $q['topic_id'] !== null ? (int) $q['topic_id'] : null,
        'explanation' => $q['explanation'],
        'answer_config' => $q['answer_config'],
        'model_answer' => $q['model_answer'],
        'options' => array_map(fn($o) => ['text' => $o['option_text'], 'correct' => (int) $o['is_correct'] === 1], $q['options']),
    ];
    $new_id = save_question($conn, $teacher_email, $data, null, $quiz_id);
    $stmt = $conn->prepare("UPDATE questions SET source_question_id = ? WHERE id = ?");
    $stmt->bind_param("ii", $question_id, $new_id);
    $stmt->execute();
    $stmt->close();
    return $new_id;
}

/**
 * Remove a question of this teacher. One that students have answered is
 * hidden (deleted_at) so their attempts keep it; an unused one is deleted.
 *
 * @return string 'hidden', 'deleted' or 'not_found'
 */
function remove_question(mysqli $conn, string $teacher_email, int $question_id): string
{
    if (!find_teacher_question($conn, $teacher_email, $question_id)) {
        return 'not_found';
    }
    $stmt = $conn->prepare("SELECT 1 FROM attempt_questions WHERE question_id = ? LIMIT 1");
    $stmt->bind_param("i", $question_id);
    $stmt->execute();
    $used = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    $stmt = $conn->prepare($used
        ? "UPDATE questions SET deleted_at = NOW() WHERE id = ? AND teacher_email = ?"
        : "DELETE FROM questions WHERE id = ? AND teacher_email = ?");
    $stmt->bind_param("is", $question_id, $teacher_email);
    $stmt->execute();
    $stmt->close();
    return $used ? 'hidden' : 'deleted';
}

/**
 * Search the teacher's bank.
 *
 * @param array $f filters: q (text), subject_id, topic_id, type, difficulty
 * @return array{rows: list<array>, total: int, page: int, pages: int}
 */
function bank_questions(mysqli $conn, string $teacher_email, array $f, int $page = 1, int $per_page = BANK_PAGE_SIZE): array
{
    $where = ["q.teacher_email = ?", "q.quiz_id IS NULL", "q.deleted_at IS NULL"];
    $types = "s";
    $params = [$teacher_email];
    if (($f['q'] ?? '') !== '') {
        $where[] = "q.question_text LIKE ?";
        $types .= "s";
        $params[] = '%' . addcslashes((string) $f['q'], '%_\\') . '%';
    }
    if (!empty($f['topic_id'])) {
        $where[] = "q.topic_id = ?";
        $types .= "i";
        $params[] = (int) $f['topic_id'];
    } elseif (!empty($f['subject_id'])) {
        $where[] = "t.subject_id = ?";
        $types .= "i";
        $params[] = (int) $f['subject_id'];
    }
    if (isset(QUESTION_TYPES[$f['type'] ?? ''])) {
        $where[] = "q.type = ?";
        $types .= "s";
        $params[] = $f['type'];
    }
    if (isset(DIFFICULTIES[$f['difficulty'] ?? ''])) {
        $where[] = "q.difficulty = ?";
        $types .= "s";
        $params[] = $f['difficulty'];
    }
    $sql_where = implode(' AND ', $where);
    $from = "FROM questions q LEFT JOIN topics t ON t.id = q.topic_id LEFT JOIN subjects s ON s.id = t.subject_id WHERE $sql_where";

    $stmt = $conn->prepare("SELECT COUNT(*) AS c $from");
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $total = (int) $stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();

    $pages = max(1, (int) ceil($total / $per_page));
    $page = min(max(1, $page), $pages);
    $offset = ($page - 1) * $per_page;
    $stmt = $conn->prepare(
        "SELECT q.*, t.name AS topic_name, s.name AS subject_name,
                (SELECT COUNT(*) FROM questions c WHERE c.source_question_id = q.id AND c.quiz_id IS NOT NULL AND c.deleted_at IS NULL) AS used_in
         $from ORDER BY q.id DESC LIMIT $per_page OFFSET $offset"
    );
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return ['rows' => attach_options($conn, $rows), 'total' => $total, 'page' => $page, 'pages' => $pages];
}

/**
 * Load each question's options (ordered) and decoded grading rules:
 * $rows[i]['options'] = [[id, question_id, option_text, is_correct]], $rows[i]['config'].
 */
function attach_options(mysqli $conn, array $rows): array
{
    $ids = array_map(fn($r) => (int) $r['id'], $rows);
    $by_q = [];
    if ($ids) {
        $res = $conn->query("SELECT id, question_id, option_text, is_correct FROM question_options WHERE question_id IN (" . implode(',', $ids) . ") ORDER BY order_index, id");
        foreach ($res->fetch_all(MYSQLI_ASSOC) as $o) {
            $by_q[(int) $o['question_id']][] = $o;
        }
    }
    foreach ($rows as &$r) {
        $r['options'] = $by_q[(int) $r['id']] ?? [];
        $r['config'] = question_config($r);
    }
    unset($r);
    return $rows;
}
