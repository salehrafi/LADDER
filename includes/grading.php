<?php
/**
 * Question types and automatic grading.
 *
 * Every question carries its grading rules in answer_config (JSON). Graders
 * are pure functions of (question snapshot, sanitized answer), so they are
 * easy to test and are used both on submit and on an explicit re-grade.
 * A result with points = null means "a teacher must grade this".
 */

const QUESTION_TYPES = [
    'mcq' => 'Multiple choice',
    'true_false' => 'True / False',
    'mcq_multi' => 'Multiple select',
    'short_answer' => 'Short answer',
    'numeric' => 'Numerical',
    'fill_blank' => 'Fill in the blanks',
    'essay' => 'Essay',
];

// Types answered by choosing options (question_options rows).
const OPTION_TYPES = ['mcq', 'true_false', 'mcq_multi'];

const DIFFICULTIES = ['easy' => 'Easy', 'medium' => 'Medium', 'hard' => 'Hard'];

const MULTI_SCORING = [
    'all_or_nothing' => 'All or nothing',
    'partial' => 'Partial credit (each wrong choice cancels a right one)',
];

const TEXT_ANSWER_MAX = 5000;
const ESSAY_ANSWER_MAX = 20000;
const BLANK_ANSWER_MAX = 500;
const NUMERIC_ANSWER_MAX = 50;

// A blank in fill-in-the-blank text: three or more underscores.
const BLANK_PATTERN = '/_{3,}/';

function question_type_label(string $type): string
{
    return QUESTION_TYPES[$type] ?? ucfirst(str_replace('_', ' ', $type));
}

function count_blanks(string $text): int
{
    return preg_match_all(BLANK_PATTERN, $text);
}

/**
 * The grading rules of a question with defaults filled in. Legacy short
 * answers (no answer_config, only model_answer) keep their original rule:
 * one accepted answer, case-insensitive, punctuation significant.
 */
function question_config(array $q): array
{
    $raw = $q['config'] ?? ($q['answer_config'] ?? null);
    $config = is_array($raw) ? $raw : (json_decode((string) $raw, true) ?: []);
    $type = $q['type'] ?? '';

    switch ($type) {
        case 'short_answer':
            if (!isset($config['accepted'])) {
                $model = trim((string) ($q['model_answer'] ?? ''));
                return ['accepted' => $model === '' ? [] : [$model], 'case_sensitive' => false, 'ignore_punctuation' => false];
            }
            return [
                'accepted' => array_values(array_filter(array_map('strval', (array) $config['accepted']), fn($a) => trim($a) !== '')),
                'case_sensitive' => !empty($config['case_sensitive']),
                'ignore_punctuation' => !empty($config['ignore_punctuation']),
            ];
        case 'numeric':
            return [
                'value' => isset($config['value']) && is_numeric($config['value']) ? (float) $config['value'] : null,
                'tolerance' => isset($config['tolerance']) && is_numeric($config['tolerance']) ? abs((float) $config['tolerance']) : 0.0,
                'tolerance_type' => ($config['tolerance_type'] ?? 'absolute') === 'percent' ? 'percent' : 'absolute',
            ];
        case 'fill_blank':
            $blanks = [];
            foreach ((array) ($config['blanks'] ?? []) as $alternatives) {
                $blanks[] = array_values(array_filter(array_map('strval', (array) $alternatives), fn($a) => trim($a) !== ''));
            }
            return [
                'blanks' => $blanks,
                'case_sensitive' => !empty($config['case_sensitive']),
                'ignore_punctuation' => array_key_exists('ignore_punctuation', $config) ? !empty($config['ignore_punctuation']) : true,
                'partial' => array_key_exists('partial', $config) ? !empty($config['partial']) : true,
            ];
        case 'mcq_multi':
            return ['scoring' => isset(MULTI_SCORING[$config['scoring'] ?? '']) ? $config['scoring'] : 'all_or_nothing'];
        case 'essay':
            return ['guidance' => trim((string) ($config['guidance'] ?? ''))];
        default:
            return [];
    }
}

/**
 * Normalise a typed answer for comparison: trimmed, inner whitespace
 * collapsed, lower-cased unless case matters, punctuation removed if asked.
 */
function normalize_answer_text(?string $s, bool $case_sensitive = false, bool $ignore_punctuation = false): string
{
    $s = trim((string) $s);
    if ($ignore_punctuation) {
        // Apostrophes and quotes join words ("it's" = "its"); other punctuation
        // separates them ("well-known" = "well known").
        $s = preg_replace("/['\x{2018}\x{2019}`\"\x{201C}\x{201D}]/u", '', $s);
        $s = preg_replace('/\p{P}+/u', ' ', $s);
    }
    $s = trim(preg_replace('/\s+/u', ' ', $s));
    return $case_sensitive ? $s : mb_strtolower($s, 'UTF-8');
}

/** Does $answer match any accepted answer under the given rules? */
function text_matches(string $answer, array $accepted, bool $case_sensitive, bool $ignore_punctuation): bool
{
    $given = normalize_answer_text($answer, $case_sensitive, $ignore_punctuation);
    if ($given === '') {
        return false;
    }
    foreach ($accepted as $a) {
        if ($given === normalize_answer_text((string) $a, $case_sensitive, $ignore_punctuation)) {
            return true;
        }
    }
    return false;
}

/** "3.5", "-2", "1e-3", "3,5" (decimal comma) → float; anything else → null. */
function parse_number(?string $s): ?float
{
    $s = str_replace([' ', "\u{00A0}"], '', trim((string) $s));
    if ($s === '') {
        return null;
    }
    if (strpos($s, ',') !== false && strpos($s, '.') === false && substr_count($s, ',') === 1) {
        $s = str_replace(',', '.', $s);
    }
    return preg_match('/^[+-]?(\d+(\.\d*)?|\.\d+)([eE][+-]?\d+)?$/', $s) ? (float) $s : null;
}

/**
 * Grade one answer.
 *
 * @param array $q snapshot row: type, points, options [[id, text, correct]], model_answer, config/answer_config, question_text
 * @param mixed $answer sanitized answer: option id string, list of ids, text, or list of blank texts
 * @return array{is_correct:?int, points:?float, selected_option_id:?int, answer_text:?string, answer_json:?string}
 */
function grade_question(array $q, $answer): array
{
    $points = (float) $q['points'];
    $type = $q['type'];
    $config = question_config($q);
    $out = ['is_correct' => null, 'points' => null, 'selected_option_id' => null, 'answer_text' => null, 'answer_json' => null];

    $full = function (bool $right) use (&$out, $points) {
        $out['is_correct'] = $right ? 1 : 0;
        $out['points'] = $right ? $points : 0.0;
    };

    switch ($type) {
        case 'mcq':
        case 'true_false':
            $selected = (is_string($answer) && ctype_digit($answer)) ? (int) $answer : null;
            $out['selected_option_id'] = $selected;
            $correct = null;
            foreach ($q['options'] ?? [] as $o) {
                if (!empty($o['correct'])) {
                    $correct = (int) $o['id'];
                }
            }
            $full($selected !== null && $selected === $correct);
            break;

        case 'mcq_multi':
            $selected = array_values(array_unique(array_map('intval', is_array($answer) ? $answer : [])));
            sort($selected);
            $out['answer_json'] = json_encode($selected);
            $right = [];
            foreach ($q['options'] ?? [] as $o) {
                if (!empty($o['correct'])) {
                    $right[] = (int) $o['id'];
                }
            }
            sort($right);
            if (!$right) {
                break; // no key: teacher grades it
            }
            if ($config['scoring'] === 'partial') {
                $hits = count(array_intersect($selected, $right));
                $wrong = count(array_diff($selected, $right));
                $score = round(max(0, $hits - $wrong) / count($right) * $points, 2);
                $out['points'] = $score;
                $out['is_correct'] = $score >= $points ? 1 : 0;
            } else {
                $full($selected === $right);
            }
            break;

        case 'short_answer':
            $text = is_string($answer) ? $answer : '';
            $out['answer_text'] = $text;
            if ($config['accepted']) {
                $full(text_matches($text, $config['accepted'], $config['case_sensitive'], $config['ignore_punctuation']));
            }
            break;

        case 'numeric':
            $text = is_string($answer) ? $answer : '';
            $out['answer_text'] = $text;
            if ($config['value'] !== null) {
                $given = parse_number($text);
                $tolerance = $config['tolerance_type'] === 'percent'
                    ? abs($config['value']) * $config['tolerance'] / 100
                    : $config['tolerance'];
                $full($given !== null && abs($given - $config['value']) <= $tolerance + 1e-9);
            }
            break;

        case 'fill_blank':
            $given = array_values(array_map('strval', is_array($answer) ? $answer : []));
            $out['answer_json'] = json_encode($given, JSON_UNESCAPED_UNICODE);
            $out['answer_text'] = implode(' | ', $given);
            $blanks = $config['blanks'];
            if ($blanks && !in_array([], $blanks, true)) {
                $right = 0;
                foreach ($blanks as $i => $accepted) {
                    if (text_matches($given[$i] ?? '', $accepted, $config['case_sensitive'], $config['ignore_punctuation'])) {
                        $right++;
                    }
                }
                if ($config['partial']) {
                    $score = round($right / count($blanks) * $points, 2);
                    $out['points'] = $score;
                    $out['is_correct'] = $right === count($blanks) ? 1 : 0;
                } else {
                    $full($right === count($blanks));
                }
            }
            break;

        case 'essay':
        default:
            $out['answer_text'] = is_string($answer) ? $answer : '';
            break; // always graded by the teacher
    }
    return $out;
}

/**
 * Keep only a well-formed answer for one question (client input is never
 * trusted). Returns null when there is nothing to keep.
 *
 * @param mixed $value raw value from the form or the autosave JSON
 * @return string|array|null
 */
function sanitize_answer_value(array $q, $value)
{
    $option_ids = array_map('intval', array_column($q['options'] ?? [], 'id'));
    switch ($q['type']) {
        case 'mcq':
        case 'true_false':
            if (is_array($value)) {
                return null;
            }
            $value = trim((string) $value);
            return (ctype_digit($value) && in_array((int) $value, $option_ids, true)) ? (string) (int) $value : null;

        case 'mcq_multi':
            $ids = [];
            foreach ((array) $value as $v) {
                $v = is_scalar($v) ? trim((string) $v) : '';
                if (ctype_digit($v) && in_array((int) $v, $option_ids, true)) {
                    $ids[(int) $v] = (string) (int) $v;
                }
            }
            return $ids ? array_values($ids) : null;

        case 'fill_blank':
            $count = max(1, count_blanks((string) $q['question_text']));
            $parts = [];
            $values = is_array($value) ? array_values($value) : [];
            $any = false;
            for ($i = 0; $i < $count; $i++) {
                $part = isset($values[$i]) && is_scalar($values[$i]) ? mb_substr(trim((string) $values[$i]), 0, BLANK_ANSWER_MAX) : '';
                $any = $any || $part !== '';
                $parts[] = $part;
            }
            return $any ? $parts : null;

        case 'numeric':
            if (is_array($value)) {
                return null;
            }
            $value = mb_substr(trim((string) $value), 0, NUMERIC_ANSWER_MAX);
            return $value === '' ? null : $value;

        case 'essay':
        case 'short_answer':
        default:
            if (is_array($value)) {
                return null;
            }
            $value = trim((string) $value);
            $max = $q['type'] === 'essay' ? ESSAY_ANSWER_MAX : TEXT_ANSWER_MAX;
            return $value === '' ? null : mb_substr($value, 0, $max);
    }
}

/** Human-readable correct answer, for review pages and the teacher. */
function correct_answer_summary(array $q): string
{
    $config = question_config($q);
    switch ($q['type']) {
        case 'mcq':
        case 'true_false':
        case 'mcq_multi':
            $right = array_map(fn($o) => $o['text'] ?? $o['option_text'] ?? '', array_filter($q['options'] ?? [], fn($o) => !empty($o['correct']) || !empty($o['is_correct'])));
            return implode('; ', $right);
        case 'short_answer':
            return implode(' / ', $config['accepted']);
        case 'numeric':
            if ($config['value'] === null) {
                return '';
            }
            $tol = $config['tolerance'] > 0
                ? ' (± ' . rtrim(rtrim(number_format($config['tolerance'], 6, '.', ''), '0'), '.') . ($config['tolerance_type'] === 'percent' ? '%' : '') . ')'
                : '';
            return rtrim(rtrim(number_format($config['value'], 6, '.', ''), '0'), '.') . $tol;
        case 'fill_blank':
            $parts = [];
            foreach ($config['blanks'] as $i => $alts) {
                $parts[] = ($i + 1) . ': ' . implode(' / ', $alts);
            }
            return implode(' · ', $parts);
        default:
            return '';
    }
}

/** Short description of the grading rule, shown to teachers. */
function grading_rule_summary(array $q): string
{
    $config = question_config($q);
    switch ($q['type']) {
        case 'short_answer':
            if (!$config['accepted']) {
                return 'Graded by hand (no accepted answer).';
            }
            return count($config['accepted']) . ' accepted answer(s)'
                . ($config['case_sensitive'] ? ', case-sensitive' : ', any case')
                . ($config['ignore_punctuation'] ? ', punctuation ignored' : '') . '.';
        case 'numeric':
            return $config['value'] === null ? 'Graded by hand (no value set).' : 'Correct within the tolerance.';
        case 'fill_blank':
            return $config['partial'] ? 'Partial credit per blank.' : 'All blanks must be right.';
        case 'mcq_multi':
            return MULTI_SCORING[$config['scoring']] . '.';
        case 'essay':
            return 'Always graded by hand.';
        default:
            return '';
    }
}
