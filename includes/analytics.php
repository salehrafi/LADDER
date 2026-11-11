<?php
/**
 * Learning analytics and reports.
 *
 * Only official attempts count (is_practice = 0). Student-facing figures use
 * only attempts whose results the teacher has released and whose answers are
 * graded; teachers see everything for their own quizzes. Topic and subject
 * figures come from the attempt snapshots, so they reflect the questions as
 * they were when taken.
 */

const ANALYTICS_MIN_TOPIC_ANSWERS = 2;

/** Percentage (0-100, one decimal) from a fraction, or null. */
function pct(?float $fraction): ?float
{
    return $fraction === null ? null : round($fraction * 100, 1);
}

function median(array $values): ?float
{
    if (!$values) {
        return null;
    }
    sort($values);
    $n = count($values);
    return $n % 2 ? (float) $values[intdiv($n, 2)] : ($values[$n / 2 - 1] + $values[$n / 2]) / 2;
}

// ---- Student --------------------------------------------------------------------------

/** @return list<array{date:string, pct:float, title:string, attempt_id:int}> released, graded official attempts, oldest first */
function student_score_trend(mysqli $conn, string $email, int $limit = 20): array
{
    $stmt = $conn->prepare(
        "SELECT qa.id, qa.submitted_at, qa.total_score / NULLIF(qa.max_score, 0) AS f, q.title
         FROM quiz_attempts qa JOIN quizzes q ON q.id = qa.quiz_id
         WHERE qa.student_email = ? AND qa.status = 'submitted' AND qa.is_practice = 0 AND qa.needs_manual_grading = 0
           AND qa.max_score > 0 AND " . results_released_sql('q') . "
         ORDER BY qa.submitted_at DESC, qa.id DESC LIMIT " . (int) $limit
    );
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $rows = array_reverse($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
    $stmt->close();
    return array_map(fn($r) => ['date' => $r['submitted_at'], 'pct' => pct((float) $r['f']), 'title' => $r['title'], 'attempt_id' => (int) $r['id']], $rows);
}

/**
 * Points earned / available per group, over graded answers of released
 * official attempts. $group is 'subject' or 'topic'.
 *
 * @return list<array{name:string, pct:float, answers:int}>
 */
function student_group_performance(mysqli $conn, string $email, string $group): array
{
    $key = $group === 'topic'
        ? "aq.topic_name"
        : "COALESCE(aq.subject_name, s.name, 'General')";
    $stmt = $conn->prepare(
        // "group_name", not "name": GROUP BY would pick subjects.name over an alias.
        "SELECT $key AS group_name, SUM(aa.points_awarded) AS earned, SUM(aq.points) AS possible, COUNT(*) AS answers
         FROM attempt_answers aa
         JOIN attempt_questions aq ON aq.attempt_id = aa.attempt_id AND aq.question_id = aa.question_id
         JOIN quiz_attempts qa ON qa.id = aa.attempt_id
         JOIN quizzes q ON q.id = qa.quiz_id
         LEFT JOIN subjects s ON s.id = q.subject_id
         WHERE qa.student_email = ? AND qa.status = 'submitted' AND qa.is_practice = 0
           AND aa.points_awarded IS NOT NULL AND aq.points > 0 AND " . results_released_sql('q') . "
           " . ($group === 'topic' ? "AND aq.topic_name IS NOT NULL" : "") . "
         GROUP BY group_name ORDER BY group_name"
    );
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return array_map(fn($r) => ['name' => $r['group_name'], 'pct' => pct((float) $r['earned'] / (float) $r['possible']), 'answers' => (int) $r['answers']], $rows);
}

/**
 * Strongest topics (60% or more) and weakest topics (under 60%), each topic
 * needing at least ANALYTICS_MIN_TOPIC_ANSWERS graded answers. A topic is
 * never in both lists.
 *
 * @return array{strong: list<array>, weak: list<array>}
 */
function student_strengths(mysqli $conn, string $email, int $n = 3): array
{
    $topics = array_values(array_filter(student_group_performance($conn, $email, 'topic'), fn($t) => $t['answers'] >= ANALYTICS_MIN_TOPIC_ANSWERS));
    usort($topics, fn($a, $b) => $b['pct'] <=> $a['pct']);
    $strong = array_slice(array_values(array_filter($topics, fn($t) => $t['pct'] >= 60)), 0, $n);
    $weak = array_slice(array_reverse(array_values(array_filter($topics, fn($t) => $t['pct'] < 60))), 0, $n);
    return ['strong' => $strong, 'weak' => $weak];
}

/**
 * The student's official assigned quizzes by status (Phase 4 statuses).
 *
 * @return array{total:int, completed:int, completed_late:int, in_progress:int, not_started:int, overdue:int, upcoming:int, rate:?float}
 */
function student_completion(mysqli $conn, string $email): array
{
    $stmt = $conn->prepare("SELECT q.* FROM quizzes q WHERE q.status IN ('published', 'closed') AND q.mode = 'official' AND " . quiz_visible_sql('q'));
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $quizzes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $out = array_fill_keys(array_keys(STATUS_LABELS), 0);
    foreach ($quizzes as $q) {
        $w = student_window($conn, $q, $email);
        $out[student_quiz_status($w, student_attempt_summary($conn, (int) $q['id'], $email))]++;
    }
    $out['total'] = count($quizzes);
    $done = $out['completed'] + $out['completed_late'];
    $due = $out['total'] - $out['upcoming'];
    $out['rate'] = $due > 0 ? round($done / $due * 100, 1) : null;
    return $out;
}

// ---- Teacher ------------------------------------------------------------------------------

/**
 * Class analytics for the teacher's own quizzes, optionally narrowed to one
 * section and/or one quiz. Uses each student's average over their official
 * graded attempts.
 *
 * @return array{students: list<array>, distribution: array<string,int>, quizzes: list<array>, sections: list<array>, topics: list<array>, summary: array}
 */
function class_analytics(mysqli $conn, string $teacher_email, int $section_id = 0, int $quiz_id = 0): array
{
    $where = ["q.teacher_email = ?", "qa.status = 'submitted'", "qa.is_practice = 0", "qa.needs_manual_grading = 0", "qa.max_score > 0"];
    $types = "s";
    $params = [$teacher_email];
    if ($quiz_id) {
        $where[] = "q.id = ?";
        $types .= "i";
        $params[] = $quiz_id;
    }
    if ($section_id) {
        $where[] = "qa.student_email IN (SELECT ss.student_email FROM section_students ss JOIN sections s ON s.id = ss.section_id WHERE ss.section_id = ? AND s.teacher_email = ?)";
        $types .= "is";
        $params[] = $section_id;
        $params[] = $teacher_email;
    }
    $w = implode(' AND ', $where);

    // Per student.
    $stmt = $conn->prepare(
        "SELECT qa.student_email, r.full_name, COUNT(*) AS attempts, COUNT(DISTINCT qa.quiz_id) AS quizzes,
                AVG(qa.total_score / qa.max_score) AS avg_f, MAX(qa.submitted_at) AS last
         FROM quiz_attempts qa JOIN quizzes q ON q.id = qa.quiz_id LEFT JOIN reg_info r ON r.email = qa.student_email
         WHERE $w GROUP BY qa.student_email, r.full_name ORDER BY avg_f DESC"
    );
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $students = array_map(fn($r) => [
        'email' => $r['student_email'], 'name' => $r['full_name'] ?? $r['student_email'], 'attempts' => (int) $r['attempts'],
        'quizzes' => (int) $r['quizzes'], 'avg' => pct((float) $r['avg_f']), 'last' => $r['last'],
    ], $stmt->get_result()->fetch_all(MYSQLI_ASSOC));
    $stmt->close();

    // Score distribution of attempts, in 10-point bands.
    $stmt = $conn->prepare("SELECT qa.total_score / qa.max_score AS f FROM quiz_attempts qa JOIN quizzes q ON q.id = qa.quiz_id WHERE $w");
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $scores = array_map(fn($r) => (float) $r['f'] * 100, $stmt->get_result()->fetch_all(MYSQLI_ASSOC));
    $stmt->close();
    $distribution = [];
    for ($b = 0; $b < 100; $b += 10) {
        $distribution[$b . '–' . ($b + 10)] = 0;
    }
    foreach ($scores as $s) {
        $band = min(90, (int) (floor($s / 10) * 10));
        $distribution[$band . '–' . ($band + 10)]++;
    }

    // Per quiz.
    $stmt = $conn->prepare(
        "SELECT q.id, q.title, COUNT(*) AS attempts, COUNT(DISTINCT qa.student_email) AS students, AVG(qa.total_score / qa.max_score) AS avg_f
         FROM quiz_attempts qa JOIN quizzes q ON q.id = qa.quiz_id WHERE $w GROUP BY q.id, q.title ORDER BY q.title"
    );
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $quizzes = array_map(fn($r) => ['id' => (int) $r['id'], 'title' => $r['title'], 'attempts' => (int) $r['attempts'], 'students' => (int) $r['students'], 'avg' => pct((float) $r['avg_f'])],
        $stmt->get_result()->fetch_all(MYSQLI_ASSOC));
    $stmt->close();

    // Per section (a student in several sections counts in each).
    $stmt = $conn->prepare(
        "SELECT s.id, s.name, COUNT(*) AS attempts, AVG(qa.total_score / qa.max_score) AS avg_f
         FROM quiz_attempts qa JOIN quizzes q ON q.id = qa.quiz_id
         JOIN section_students ss ON ss.student_email = qa.student_email
         JOIN sections s ON s.id = ss.section_id AND s.teacher_email = q.teacher_email
         WHERE $w GROUP BY s.id, s.name ORDER BY s.name"
    );
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $sections = array_map(fn($r) => ['id' => (int) $r['id'], 'name' => $r['name'], 'attempts' => (int) $r['attempts'], 'avg' => pct((float) $r['avg_f'])],
        $stmt->get_result()->fetch_all(MYSQLI_ASSOC));
    $stmt->close();

    // Per topic, class-wide (graded answers).
    $tw = str_replace(["qa.needs_manual_grading = 0", "qa.max_score > 0"], ["aa.points_awarded IS NOT NULL", "aq.points > 0"], $w);
    $stmt = $conn->prepare(
        "SELECT aq.topic_name AS name, SUM(aa.points_awarded) AS earned, SUM(aq.points) AS possible, COUNT(*) AS answers
         FROM attempt_answers aa
         JOIN attempt_questions aq ON aq.attempt_id = aa.attempt_id AND aq.question_id = aa.question_id
         JOIN quiz_attempts qa ON qa.id = aa.attempt_id JOIN quizzes q ON q.id = qa.quiz_id
         WHERE $tw AND aq.topic_name IS NOT NULL GROUP BY aq.topic_name ORDER BY SUM(aa.points_awarded) / SUM(aq.points) ASC, aq.topic_name"
    );
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $topics = array_map(fn($r) => ['name' => $r['name'], 'pct' => pct((float) $r['earned'] / (float) $r['possible']), 'answers' => (int) $r['answers']],
        $stmt->get_result()->fetch_all(MYSQLI_ASSOC));
    $stmt->close();

    $avgs = array_column($students, 'avg');
    return [
        'students' => $students,
        'distribution' => $distribution,
        'quizzes' => $quizzes,
        'sections' => $sections,
        'topics' => $topics,
        'summary' => [
            'students' => count($students),
            'attempts' => count($scores),
            'average' => $scores ? round(array_sum($scores) / count($scores), 1) : null,
            'median' => $scores ? round(median($scores), 1) : null,
            'pass_rate' => $scores ? round(count(array_filter($scores, fn($s) => $s >= 50)) / count($scores) * 100, 1) : null,
            'student_average' => $avgs ? round(array_sum($avgs) / count($avgs), 1) : null,
        ],
    ];
}

/**
 * Item analysis for a quiz (official submitted attempts):
 *  - difficulty: average fraction of points earned (higher = easier)
 *  - discrimination: average in the top 27% of attempts minus the bottom 27%
 *    (by total score); above 0.3 separates strong and weak students well,
 *    near or below 0 suggests a confusing question or a wrong key
 *  - for option questions, how often each option was chosen.
 *
 * @return list<array>
 */
function question_analysis(mysqli $conn, int $quiz_id): array
{
    $attempts = $conn->query(
        "SELECT id, total_score / NULLIF(max_score, 0) AS f FROM quiz_attempts
         WHERE quiz_id = " . (int) $quiz_id . " AND status = 'submitted' AND is_practice = 0 ORDER BY f DESC"
    )->fetch_all(MYSQLI_ASSOC);
    if (!$attempts) {
        return [];
    }
    $n = count($attempts);
    $k = max(1, (int) round($n * 0.27));
    $upper = array_flip(array_map('intval', array_column(array_slice($attempts, 0, $k), 'id')));
    $lower = array_flip(array_map('intval', array_column(array_slice($attempts, -$k), 'id')));
    $ids = implode(',', array_map('intval', array_column($attempts, 'id')));

    $rows = $conn->query(
        "SELECT aa.attempt_id, aa.question_id, aa.points_awarded, aa.selected_option_id, aa.answer_json,
                aq.points, aq.type, aq.question_text, aq.options_json, aq.position
         FROM attempt_answers aa
         JOIN attempt_questions aq ON aq.attempt_id = aa.attempt_id AND aq.question_id = aa.question_id
         WHERE aa.attempt_id IN ($ids)"
    )->fetch_all(MYSQLI_ASSOC);

    $live = [];
    foreach ($conn->query("SELECT id, order_index, deleted_at FROM questions WHERE quiz_id = " . (int) $quiz_id)->fetch_all(MYSQLI_ASSOC) as $q) {
        $live[(int) $q['id']] = $q;
    }

    $items = [];
    foreach ($rows as $r) {
        $qid = (int) $r['question_id'];
        if (!isset($items[$qid])) {
            $items[$qid] = [
                'question_id' => $qid, 'text' => $r['question_text'], 'type' => $r['type'],
                'order' => isset($live[$qid]) ? (int) $live[$qid]['order_index'] : 9999,
                'removed' => !isset($live[$qid]) || $live[$qid]['deleted_at'] !== null,
                'answered' => 0, 'graded' => 0, 'sum' => 0.0, 'up' => [], 'low' => [], 'options' => [], 'blank' => 0,
            ];
            foreach (json_decode((string) $r['options_json'], true) ?: [] as $o) {
                $items[$qid]['options'][(int) $o['id']] = ['text' => $o['text'], 'correct' => !empty($o['correct']), 'count' => 0];
            }
        }
        $it = &$items[$qid];
        $it['answered']++;
        if ($r['points_awarded'] !== null && (float) $r['points'] > 0) {
            $f = (float) $r['points_awarded'] / (float) $r['points'];
            $it['graded']++;
            $it['sum'] += $f;
            $aid = (int) $r['attempt_id'];
            if (isset($upper[$aid])) {
                $it['up'][] = $f;
            }
            if (isset($lower[$aid])) {
                $it['low'][] = $f;
            }
        }
        $chosen = $r['selected_option_id'] !== null ? [(int) $r['selected_option_id']]
            : array_map('intval', json_decode((string) ($r['type'] === 'mcq_multi' ? $r['answer_json'] : ''), true) ?: []);
        if (in_array($r['type'], OPTION_TYPES, true)) {
            if (!$chosen) {
                $it['blank']++;
            }
            foreach ($chosen as $oid) {
                if (isset($it['options'][$oid])) {
                    $it['options'][$oid]['count']++;
                }
            }
        }
        unset($it);
    }

    $out = [];
    foreach ($items as $it) {
        $difficulty = $it['graded'] ? $it['sum'] / $it['graded'] : null;
        $disc = ($it['up'] && $it['low'] && $n >= 4)
            ? round(array_sum($it['up']) / count($it['up']) - array_sum($it['low']) / count($it['low']), 2)
            : null;
        $flag = '';
        if ($difficulty !== null && $difficulty < 0.3) {
            $flag = 'Very hard';
        } elseif ($difficulty !== null && $difficulty > 0.9) {
            $flag = 'Very easy';
        }
        if ($disc !== null && $disc < 0.1) {
            $flag = trim($flag . ' · Low discrimination: check the wording or key', ' ·');
        }
        $out[] = [
            'question_id' => $it['question_id'], 'text' => $it['text'], 'type' => $it['type'], 'order' => $it['order'], 'removed' => $it['removed'],
            'answered' => $it['answered'], 'difficulty' => $difficulty === null ? null : round($difficulty, 2),
            'discrimination' => $disc, 'flag' => $flag, 'options' => array_values($it['options']), 'blank' => $it['blank'],
        ];
    }
    usort($out, fn($a, $b) => [$a['order'], $a['question_id']] <=> [$b['order'], $b['question_id']]);
    return $out;
}
