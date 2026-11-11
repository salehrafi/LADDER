<?php
/**
 * Import quiz questions from a Markdown file, usually written by an AI chat
 * assistant from the prompt that question_prompt_template() produces.
 *
 * Format (one "##" heading per question):
 *
 *     ## Q1 [mcq] (1 point)
 *     What is the main pigment in photosynthesis?
 *     - [ ] Carotene
 *     - [x] Chlorophyll
 *
 *     ## Q2 [short] (2 points)
 *     Which gas do plants release?
 *     Answer: Oxygen
 *
 * More types (see sample_question_markdown_all_types()):
 *
 *     ## Q3 [tf]        Answer: True          (or - [x] True / - [ ] False)
 *     ## Q4 [multi]     several - [x] options, or Answer: A, C
 *     ## Q5 [numeric]   Answer: 9.81 ± 0.05   (also "+/- 2%")
 *     ## Q6 [blank]     text with ___ blanks; Answer: Paris; France | French Republic
 *     ## Q7 [essay]     no answer, graded by hand
 *
 * Short answers accept alternatives separated by "|". An "Explanation:" line
 * is kept and shown to students with the correct answer.
 *
 * The parser is forgiving about what AI tools tend to vary (a wrapping code
 * fence, "marks" instead of "points", lettered options, a missing type tag)
 * and strict about what would grade wrongly (an MCQ without exactly one
 * correct option).
 */

require_once __DIR__ . '/grading.php';

const MD_IMPORT_MAX_BYTES = 512 * 1024;
const MD_IMPORT_MAX_QUESTIONS = 200;
const MD_QUESTION_MAX_CHARS = 5000;
const MD_OPTION_MAX_CHARS = 500;
const MD_MAX_POINTS = 999.99;   // questions.points is DECIMAL(5,2)

/**
 * Parse Markdown into questions.
 *
 * @return array{questions: list<array{line:int, type:string, text:string, points:float,
 *               options:list<string>, correct:int, answer:?string}>, errors: list<string>}
 *         For an MCQ, `correct` is the index of the correct option; for a short
 *         answer it is -1 and `answer` holds the model answer (null = grade by hand).
 */
function parse_question_markdown(string $markdown): array
{
    $markdown = preg_replace('/^\xEF\xBB\xBF/', '', $markdown);
    if (!mb_check_encoding($markdown, 'UTF-8')) {
        return ['questions' => [], 'errors' => ['The file is not valid UTF-8 text. Save it as UTF-8 and try again.']];
    }
    $lines = preg_split('/\R/u', $markdown);
    $lines = strip_wrapping_fence($lines);

    $blocks = [];
    $current = null;
    foreach ($lines as $i => $line) {
        if (preg_match('/^\s{0,3}#{2,4}\s+(.*)$/u', $line, $m)) {
            if ($current !== null) {
                $blocks[] = $current;
            }
            $current = ['line' => $i + 1, 'heading' => trim($m[1]), 'body' => []];
        } elseif ($current !== null) {
            $current['body'][] = ['line' => $i + 1, 'text' => $line];
        }
        // Anything before the first question (a "# Title", an intro) is ignored.
    }
    if ($current !== null) {
        $blocks[] = $current;
    }

    if (!$blocks) {
        return ['questions' => [], 'errors' => ['No questions found. Each question must start with a "## " heading, e.g. "## Q1 [mcq] (1 point)".']];
    }
    if (count($blocks) > MD_IMPORT_MAX_QUESTIONS) {
        return ['questions' => [], 'errors' => ['Too many questions: ' . count($blocks) . ' (at most ' . MD_IMPORT_MAX_QUESTIONS . ' per import).']];
    }

    $questions = [];
    $errors = [];
    foreach ($blocks as $n => $block) {
        $label = 'Question ' . ($n + 1) . ' (line ' . $block['line'] . ')';
        $q = parse_question_block($block, $problem);
        if ($problem !== null) {
            $errors[] = "$label: $problem";
        } else {
            $questions[] = $q;
        }
    }
    return ['questions' => $questions, 'errors' => $errors];
}

/** Drop a ```markdown ... ``` fence that wraps the whole document. */
function strip_wrapping_fence(array $lines): array
{
    $first = null;
    $last = null;
    foreach ($lines as $i => $line) {
        if (trim($line) !== '') {
            $first ??= $i;
            $last = $i;
        }
    }
    if ($first !== null && $first !== $last
        && preg_match('/^\s*(```|~~~)/', $lines[$first])
        && preg_match('/^\s*(```|~~~)\s*$/', $lines[$last])) {
        $lines[$first] = '';
        $lines[$last] = '';
    }
    return $lines;
}

/**
 * Turn one "## ..." block into a question, or set $problem and return null.
 */
function parse_question_block(array $block, ?string &$problem): ?array
{
    $problem = null;
    $heading = $block['heading'];

    $type = null;
    $tags = [
        'mcq' => 'mcq|multiple[\s_-]*choice|single[\s_-]*choice',
        'mcq_multi' => 'multi(?:ple)?[\s_-]*(?:select|answers?|response)?|checkbox(?:es)?',
        'true_false' => 'tf|t\/f|true[\s_\/-]*(?:or[\s_-]*)?false',
        'short_answer' => 'short(?:[\s_-]*answer)?',
        'numeric' => 'numeric(?:al)?|number',
        'fill_blank' => 'blanks?|fill(?:[\s_-]*in)?(?:[\s_-]*the)?(?:[\s_-]*blanks?)?|cloze',
        'essay' => 'essay|long(?:[\s_-]*answer)?',
    ];
    foreach ($tags as $tag_type => $pattern) {
        if (preg_match('/[\[(]\s*(?:' . $pattern . ')\s*[\])]/iu', $heading, $m)) {
            $type = $tag_type;
            $heading = str_replace($m[0], ' ', $heading);
            break;
        }
    }

    $points = 1.0;
    if (preg_match('/[\[(]\s*(\d+(?:[.,]\d+)?)\s*(?:points?|pts?|marks?)\s*[\])]/iu', $heading, $m)) {
        $points = (float) str_replace(',', '.', $m[1]);
        $heading = str_replace($m[0], ' ', $heading);
    }

    // What is left of the heading after "Q1." is the start of the question, if any.
    // Only "Q1", "Question 2:" or "3." are labels; a bare "5 + 3 = ?" is question text.
    $heading = preg_replace('/^\s*(?:Q(?:uestion)?\s*\d+\s*[.:)\-–]?|\d+\s*[.:)\-–])?\s*/iu', '', $heading);
    $text_lines = trim($heading) !== '' ? [trim($heading)] : [];

    $options = [];
    $correct = [];
    $answer = null;
    $has_answer_line = false;
    $explanation = null;
    foreach ($block['body'] as $row) {
        $line = $row['text'];
        $trimmed = trim($line);

        if (preg_match('/^(?:[-*+]|\d+[.)])\s*\[([ xX✓✔]?)\]\s*(.*)$/u', $trimmed, $m)) {
            $text = clean_option_text($m[2]);
            if ($text === '') {
                $problem = "an option on line {$row['line']} is empty.";
                return null;
            }
            if (mb_strlen($text) > MD_OPTION_MAX_CHARS) {
                $problem = "the option on line {$row['line']} is longer than " . MD_OPTION_MAX_CHARS . ' characters.';
                return null;
            }
            if (trim($m[1]) !== '') {
                $correct[] = count($options);
            }
            $options[] = $text;
        } elseif (preg_match('/^\**\s*(?:model\s+|correct\s+)?answer\s*\**\s*:\s*\**\s*(.*?)\s*\**\s*$/iu', $trimmed, $m)) {
            $has_answer_line = true;
            $answer = $m[1];
        } elseif (preg_match('/^\**\s*(?:explanation|solution)\s*\**\s*:\s*\**\s*(.*?)\s*\**\s*$/iu', $trimmed, $m)) {
            $explanation = $m[1] === '' ? null : mb_substr($m[1], 0, MD_QUESTION_MAX_CHARS);
        } elseif (preg_match('/^\**\s*(?:note|hint)\s*\**\s*:/iu', $trimmed)
            || preg_match('/^(-{3,}|\*{3,}|_{3,})$/', $trimmed)) {
            continue;   // AI extras, horizontal rules
        } elseif ($trimmed === '') {
            if ($text_lines && !$options) {
                $text_lines[] = '';
            }
        } elseif ($options) {
            $problem = "unexpected text after the options on line {$row['line']}: \"" . mb_strimwidth($trimmed, 0, 60, '…') . '"';
            return null;
        } else {
            $text_lines[] = rtrim($line);
        }
    }

    $text = trim(implode("\n", $text_lines));
    if ($text === '') {
        return fail_block($problem, 'the question text is missing.');
    }
    if (mb_strlen($text) > MD_QUESTION_MAX_CHARS) {
        return fail_block($problem, 'the question text is longer than ' . MD_QUESTION_MAX_CHARS . ' characters.');
    }
    if ($points <= 0 || $points > MD_MAX_POINTS) {
        return fail_block($problem, 'points must be more than 0 and at most ' . MD_MAX_POINTS . '.');
    }
    $points = round($points, 2);

    $type ??= $options ? 'mcq' : 'short_answer';
    $base = ['line' => $block['line'], 'text' => $text, 'points' => $points, 'explanation' => $explanation];

    if ($type === 'true_false') {
        if ($options) {
            $labels = array_map('mb_strtolower', $options);
            if ($labels !== ['true', 'false'] || count($correct) !== 1) {
                return fail_block($problem, 'a true/false question needs the options "True" and "False" with one marked [x], or an "Answer: True/False" line.');
            }
            $is_true = $correct[0] === 0;
        } else {
            $a = mb_strtolower(trim((string) $answer));
            if (!in_array($a, ['true', 'false', 't', 'f', 'yes', 'no'], true)) {
                return fail_block($problem, 'a true/false question needs "Answer: True" or "Answer: False".');
            }
            $is_true = in_array($a, ['true', 't', 'yes'], true);
        }
        return $base + ['type' => 'true_false', 'options' => ['True', 'False'], 'correct' => $is_true ? 0 : 1, 'answer' => null, 'config' => null];
    }

    if ($type === 'mcq_multi') {
        if (count($options) < 2) {
            return fail_block($problem, 'a multiple-select question needs at least 2 options written as "- [ ] option".');
        }
        if (!$correct && $has_answer_line) {
            foreach (preg_split('/\s*[,;]\s*|\s+and\s+/iu', trim((string) $answer)) as $piece) {
                $correct = array_merge($correct, correct_from_answer_line($piece, $options));
            }
            $correct = array_values(array_unique($correct));
        }
        if (!$correct) {
            return fail_block($problem, 'no option is marked correct. Mark every correct option with [x].');
        }
        return $base + ['type' => 'mcq_multi', 'options' => $options, 'correct' => -1, 'correct_list' => $correct, 'answer' => null,
            'config' => ['scoring' => 'all_or_nothing']];
    }

    if ($type === 'numeric') {
        if ($options) {
            return fail_block($problem, 'a numerical question cannot have options.');
        }
        $raw = trim((string) $answer);
        $tolerance = 0.0;
        $tolerance_type = 'absolute';
        if (preg_match('/^(.*?)\s*(?:±|\+\/-|\+-|\(\s*(?:tolerance|tol\.?)\s*:?)\s*([0-9.,]+)\s*(%?)\s*\)?\s*$/iu', $raw, $m)) {
            $raw = $m[1];
            $tolerance = (float) str_replace(',', '.', $m[2]);
            $tolerance_type = $m[3] === '%' ? 'percent' : 'absolute';
        }
        $value = parse_number($raw);
        if ($value === null) {
            return fail_block($problem, 'a numerical question needs "Answer: <number>", optionally with "± <tolerance>".');
        }
        return $base + ['type' => 'numeric', 'options' => [], 'correct' => -1, 'answer' => (string) $value,
            'config' => ['value' => $value, 'tolerance' => $tolerance, 'tolerance_type' => $tolerance_type]];
    }

    if ($type === 'fill_blank') {
        $count = count_blanks($text);
        if ($count === 0) {
            return fail_block($problem, 'a fill-in-the-blank question needs at least one blank written as ___ in the text.');
        }
        $blanks = array_map(
            fn($part) => array_values(array_filter(array_map('trim', explode('|', $part)), fn($a) => $a !== '')),
            array_map('trim', explode(';', (string) $answer))
        );
        $blanks = array_values(array_filter($blanks));
        if (count($blanks) !== $count) {
            return fail_block($problem, "the text has $count blank(s) but the Answer line gives " . count($blanks) . '. Separate blanks with ";" and alternatives with "|".');
        }
        return $base + ['type' => 'fill_blank', 'options' => [], 'correct' => -1, 'answer' => null,
            'config' => ['blanks' => $blanks, 'case_sensitive' => false, 'ignore_punctuation' => true, 'partial' => true]];
    }

    if ($type === 'essay') {
        if ($options) {
            return fail_block($problem, 'an essay question cannot have options.');
        }
        return $base + ['type' => 'essay', 'options' => [], 'correct' => -1, 'answer' => null, 'config' => ['guidance' => '']];
    }

    if ($type === 'short_answer') {
        if ($options) {
            return fail_block($problem, 'it is marked [short] but has options. Use [mcq], or remove the options.');
        }
        $answer = $answer === null ? null : trim($answer);
        if ($answer === '' || ($answer !== null && preg_match('/^(none|n\/a|-+|manual(?:\s+grading)?)$/i', $answer))) {
            $answer = null;
        }
        // Alternatives: "Oxygen | O2". The first one is kept as the model answer.
        $accepted = $answer === null ? [] : array_values(array_filter(array_map('trim', explode('|', $answer)), fn($a) => $a !== ''));
        return ['line' => $block['line'], 'type' => 'short_answer', 'text' => $text, 'points' => $points,
            'options' => [], 'correct' => -1, 'answer' => $accepted[0] ?? null, 'explanation' => $explanation,
            'config' => ['accepted' => $accepted, 'case_sensitive' => false, 'ignore_punctuation' => false]];
    }

    if (count($options) < 2) {
        return fail_block($problem, 'a multiple-choice question needs at least 2 options written as "- [ ] option".');
    }
    if (!$correct && $has_answer_line) {
        $correct = correct_from_answer_line((string) $answer, $options);
    }
    if (count($correct) !== 1) {
        return fail_block($problem, $correct
            ? 'more than one option is marked correct. Mark exactly one with [x].'
            : 'no option is marked correct. Mark the correct one with [x].');
    }
    return ['line' => $block['line'], 'type' => 'mcq', 'text' => $text, 'points' => $points,
        'options' => $options, 'correct' => $correct[0], 'answer' => null, 'explanation' => $explanation, 'config' => null];
}

function fail_block(?string &$problem, string $message): ?array
{
    $problem = $message;
    return null;
}

/** "A) Chlorophyll" or "**Chlorophyll**" → "Chlorophyll". */
function clean_option_text(string $text): string
{
    $text = trim($text);
    $text = preg_replace('/^\(?[A-H][.)]\s+/u', '', $text);
    $text = preg_replace('/\s*(?:✓|✔|\(correct\))\s*$/iu', '', $text);
    if (preg_match('/^\*\*(.+)\*\*$/u', $text, $m)) {
        $text = $m[1];
    }
    return trim($text);
}

/** "Answer: B" or "Answer: Chlorophyll" → [index of that option]. */
function correct_from_answer_line(string $answer, array $options): array
{
    $answer = trim($answer);
    if (preg_match('/^\(?([A-H])\)?[.)]?$/i', $answer, $m)) {
        $index = ord(strtoupper($m[1])) - ord('A');
        return $index < count($options) ? [$index] : [];
    }
    $key = mb_strtolower(clean_option_text($answer));
    foreach ($options as $i => $option) {
        if (mb_strtolower($option) === $key) {
            return [$i];
        }
    }
    return [];
}

/**
 * Append parsed questions to a quiz in one transaction. The caller has
 * already checked that the teacher owns the quiz.
 *
 * @return int number of questions added
 */
function import_questions(mysqli $conn, int $quiz_id, array $questions): int
{
    $stmt = $conn->prepare("SELECT teacher_email FROM quizzes WHERE id = ?");
    $stmt->bind_param("i", $quiz_id);
    $stmt->execute();
    $teacher = (string) ($stmt->get_result()->fetch_assoc()['teacher_email'] ?? '');
    $stmt->close();

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("SELECT COALESCE(MAX(order_index), -1) + 1 AS next_order FROM questions WHERE quiz_id = ?");
        $stmt->bind_param("i", $quiz_id);
        $stmt->execute();
        $order = (int) $stmt->get_result()->fetch_assoc()['next_order'];
        $stmt->close();

        $q_stmt = $conn->prepare(
            "INSERT INTO questions (quiz_id, teacher_email, type, question_text, points, order_index, model_answer, explanation, answer_config)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $o_stmt = $conn->prepare("INSERT INTO question_options (question_id, option_text, is_correct, order_index) VALUES (?, ?, ?, ?)");
        foreach ($questions as $q) {
            $type = $q['type'];
            $text = $q['text'];
            $points = (float) $q['points'];
            $answer = $q['answer'];
            $explanation = $q['explanation'] ?? null;
            $config = isset($q['config']) && $q['config'] !== null ? json_encode($q['config'], JSON_UNESCAPED_UNICODE) : null;
            $q_stmt->bind_param("isssdisss", $quiz_id, $teacher, $type, $text, $points, $order, $answer, $explanation, $config);
            $q_stmt->execute();
            $question_id = $q_stmt->insert_id;
            $order++;

            $right = $q['correct_list'] ?? [$q['correct']];
            foreach ($q['options'] as $i => $option) {
                $is_correct = in_array($i, $right, true) ? 1 : 0;
                $o_stmt->bind_param("isii", $question_id, $option, $is_correct, $i);
                $o_stmt->execute();
            }
        }
        $q_stmt->close();
        $o_stmt->close();
        $conn->commit();
        return count($questions);
    } catch (Throwable $ex) {
        $conn->rollback();
        throw $ex;
    }
}

/** Defaults for the prompt builder on the Questions page. */
function question_prompt_defaults(): array
{
    return [
        'topic' => 'Photosynthesis',
        'level' => 'Class 9',
        'difficulty' => 'mixed',
        'language' => 'English',
        'mcq' => 10,
        'short' => 5,
        'mcq_points' => 1,
        'short_points' => 2,
        'extra' => '',
    ];
}

/**
 * The prompt a teacher pastes into ChatGPT, Claude, Gemini, etc. Placeholders
 * are {{name}}; the page's script fills the same template live, so this is
 * the single source for both.
 */
function question_prompt_template(): string
{
    return <<<'PROMPT'
You are helping a teacher write a quiz.

Topic: {{topic}}
Students: {{level}}
Difficulty: {{difficulty}}
Language of the questions: {{language}}
Write exactly {{mcq}} multiple-choice questions and {{short}} short-answer questions.
Each multiple-choice question is worth {{mcq_points}} point(s); each short-answer question is worth {{short_points}} point(s).
{{extra}}

Rules for the questions:
- Every multiple-choice question has 4 options and exactly one correct option. Vary the position of the correct option.
- Every short-answer question has one short, unambiguous answer (a word, a number or a short phrase), so it can be marked automatically.
- Questions must be factually correct and suit the students' level.

Reply with ONLY a Markdown document in exactly this format, with nothing before or after it:

# Quiz: <short title>

## Q1 [mcq] ({{mcq_points}} point)
<question text>
- [ ] <wrong option>
- [x] <correct option>
- [ ] <wrong option>
- [ ] <wrong option>

## Q2 [short] ({{short_points}} points)
<question text>
Answer: <the short answer>
Generate the requested content and output it ONLY inside a Markdown code block (```markdown ... ```).

Important formatting rules:
- Start every question with "## Q<number>", then [mcq] or [short], then the points in round brackets.
- Mark the correct option with [x] and every other option with [ ].
- Do not put letters (A, B, C) or numbers in front of options.
- Do not add explanations, hints or any other text.
- Do not use WritingBlock or any special document editor.
- Do not render the content as a formatted document.
- Put the entire output inside a plain Markdown code block.
- Make it easy to copy and paste directly into a .md file.
- Do not write any introduction, explanation, or text outside the code block.
- Preserve the requested Markdown syntax exactly.
PROMPT;
}

/** Fill the prompt template. Unknown or empty values fall back to defaults. */
function build_question_prompt(array $values = []): string
{
    $v = array_merge(question_prompt_defaults(), array_filter($values, fn($x) => $x !== null && $x !== ''));
    $extra = trim((string) $v['extra']);
    $template = question_prompt_template();
    $template = $extra === ''
        ? str_replace("{{extra}}\n", '', $template)
        : str_replace('{{extra}}', 'Extra instructions: ' . $extra, $template);

    $pairs = [];
    foreach ($v as $key => $value) {
        $pairs['{{' . $key . '}}'] = (string) $value;
    }
    return strtr($template, $pairs);
}

/** A ready-made file teachers can download to see the format. */
function sample_question_markdown(): string
{
    return <<<'MD'
# Quiz: Photosynthesis

## Q1 [mcq] (1 point)
Which pigment gives leaves their green colour?
- [ ] Carotene
- [x] Chlorophyll
- [ ] Xanthophyll
- [ ] Anthocyanin

## Q2 [mcq] (1 point)
Where in the cell does photosynthesis take place?
- [ ] Mitochondria
- [ ] Nucleus
- [x] Chloroplast
- [ ] Ribosome

## Q3 [short] (2 points)
Which gas do plants release during photosynthesis?
Answer: Oxygen

## Q4 [short] (3 points)
Explain in one or two sentences why sunlight is needed for photosynthesis.
Answer: none

MD;
}

/** A sample showing every question type the importer understands. */
function sample_question_markdown_all_types(): string
{
    return <<<'MD'
# Quiz: Every question type

## Q1 [mcq] (1 point)
Which planet is closest to the Sun?
- [x] Mercury
- [ ] Venus
- [ ] Earth
Explanation: Mercury orbits closest to the Sun.

## Q2 [tf] (1 point)
Water boils at 100 °C at sea level.
Answer: True

## Q3 [multi] (2 points)
Which of these are prime numbers?
- [x] 2
- [x] 3
- [ ] 4
- [x] 5

## Q4 [short] (2 points)
Which gas do plants release during photosynthesis?
Answer: Oxygen | O2

## Q5 [numeric] (2 points)
What is the acceleration due to gravity on Earth, in m/s²?
Answer: 9.81 ± 0.05

## Q6 [blank] (2 points)
The capital of France is ___ and it lies on the river ___.
Answer: Paris; Seine

## Q7 [essay] (5 points)
Explain how photosynthesis supports life on Earth.

MD;
}
