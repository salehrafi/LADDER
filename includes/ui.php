<?php
/**
 * Small presentation helpers shared by the page templates: inline SVG icons,
 * sidebar links and the toast region for flash messages.
 */

/** Simple square-cornered line icons (24x24, stroke = currentColor). */
const UI_ICONS = [
    'home' => '<path d="M3 11l9-7 9 7"/><path d="M5 10v10h5v-6h4v6h5V10"/>',
    'quiz' => '<path d="M6 3h9l4 4v14H6z"/><path d="M14 3v5h5"/><path d="M9 13h7M9 17h7M9 9h3"/>',
    'bank' => '<path d="M4 5h16v4H4zM4 10h16v4H4zM4 15h16v4H4z"/><path d="M8 7h.01M8 12h.01M8 17h.01"/>',
    'assign' => '<path d="M4 4h16v16H4z"/><path d="M8 12l3 3 5-6"/>',
    'results' => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
    'classes' => '<path d="M16 19v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><path d="M9 3a4 4 0 1 1 0 8 4 4 0 0 1 0-8z"/><path d="M22 19v-2a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8"/>',
    'student' => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><path d="M12 3a4 4 0 1 1 0 8 4 4 0 0 1 0-8z"/>',
    'settings' => '<path d="M12 9a3 3 0 1 1 0 6 3 3 0 0 1 0-6z"/><path d="M19.4 15l1.6 1-2 3.5-1.8-.7a7 7 0 0 1-2.2 1.3L14.7 22h-4l-.3-1.9a7 7 0 0 1-2.2-1.3l-1.8.7-2-3.5 1.6-1a7 7 0 0 1 0-2.6L4.4 11l2-3.5 1.8.7a7 7 0 0 1 2.2-1.3L10.7 3h4l.3 1.9a7 7 0 0 1 2.2 1.3l1.8-.7 2 3.5-1.6 1a7 7 0 0 1 0 2.6z"/>',
    'logout' => '<path d="M9 21H4V3h5"/><path d="M16 17l5-5-5-5M21 12H9"/>',
    'bell' => '<path d="M18 16V11a6 6 0 1 0-12 0v5l-2 2h16z"/><path d="M10 21h4"/>',
    'bookmark' => '<path d="M6 3h12v18l-6-4-6 4z"/>',
    'practice' => '<path d="M12 3a9 9 0 1 1 0 18 9 9 0 0 1 0-18z"/><path d="M12 8a4 4 0 1 1 0 8 4 4 0 0 1 0-8z"/><path d="M12 12h.01"/>',
    'clock' => '<path d="M12 3a9 9 0 1 1 0 18 9 9 0 0 1 0-18z"/><path d="M12 7v5l3 3"/>',
    'check' => '<path d="M4 12l5 5L20 6"/>',
    'alert' => '<path d="M12 3l10 18H2z"/><path d="M12 10v5M12 18h.01"/>',
    'users' => '<path d="M16 19v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><path d="M9 3a4 4 0 1 1 0 8 4 4 0 0 1 0-8z"/><path d="M22 19v-2a4 4 0 0 0-3-3.9"/>',
    'chart' => '<path d="M3 3v18h18"/><path d="M7 15l4-4 3 3 5-6"/>',
    'menu' => '<path d="M3 6h18M3 12h18M3 18h18"/>',
    'pending' => '<path d="M12 3a9 9 0 1 1 0 18 9 9 0 0 1 0-18z"/><path d="M8 12h.01M12 12h.01M16 12h.01"/>',
    'download' => '<path d="M12 3v12M7 10l5 5 5-5M4 21h16"/>',
    'print' => '<path d="M6 9V3h12v6M6 18H3v-8h18v8h-3"/><path d="M6 14h12v7H6z"/>',
];

/** An inline SVG icon (decorative unless $label is given). */
function icon(string $name, string $label = ''): string
{
    $paths = UI_ICONS[$name] ?? UI_ICONS['quiz'];
    $a11y = $label === '' ? 'aria-hidden="true" focusable="false"' : 'role="img" aria-label="' . e($label) . '"';
    return '<svg class="icon" viewBox="0 0 24 24" ' . $a11y . '>' . $paths . '</svg>';
}

/** One sidebar link; marks the current page for screen readers too. */
function nav_link(string $href, string $label, string $icon_name, string $key, string $active, int $count = 0): string
{
    $is = $key === $active;
    return '<li><a href="' . e($href) . '"' . ($is ? ' class="active" aria-current="page"' : '') . '>'
        . icon($icon_name) . '<span>' . e($label) . '</span>'
        . ($count > 0 ? '<span class="nav-count" aria-label="' . $count . ' unread">' . ($count > 99 ? '99+' : $count) . '</span>' : '')
        . '</a></li>';
}

/** Queued flash messages as dismissible toasts (shown once). */
function render_toasts(): string
{
    $messages = take_flashes();
    $html = '<div class="toast-region" role="status" aria-live="polite">';
    foreach ($messages as $m) {
        $type = in_array($m['type'], ['success', 'error', 'warning', 'info'], true) ? $m['type'] : 'info';
        $html .= '<div class="toast toast-' . $type . '"><span>' . e($m['message']) . '</span>'
            . '<button type="button" class="toast-close" aria-label="Dismiss">&times;</button></div>';
    }
    return $html . '</div>';
}

/**
 * The question list of the take-quiz page. Used by take_quiz.php and, for
 * exam mode, by begin_quiz.php: a locked quiz sends no questions to the
 * browser until the student has passed the start gate.
 *
 * @param array<int,array> $questions_by_id id => [id, type, question_text, points]
 * @param array<int,array> $options_by_question id => [[id, option_text], ...] (no correct flags)
 * @param array<string,string> $draft autosaved answers
 */
function render_quiz_questions(array $questions_by_id, array $options_by_question, array $draft, bool $practice = false): string
{
    ob_start();
    $number = 0;
    foreach ($questions_by_id as $qid => $q):
        $field = "q_$qid";
        $saved_value = $draft[$field] ?? '';
        $number++;
        ?>
        <section class="question-block" id="q-<?php echo (int) $qid; ?>" aria-labelledby="q-label-<?php echo (int) $qid; ?>">
            <div class="question-head">
                <span class="q-number" id="q-label-<?php echo (int) $qid; ?>">Question <?php echo $number; ?></span>
                <span class="points-tag"><?php echo fmt_score($q['points']); ?> pt<?php echo (float) $q['points'] == 1 ? '' : 's'; ?></span>
            </div>
            <?php if ($q['type'] === 'fill_blank'): ?>
                <p class="question-text fill-blank-text"><?php
                    // Each ___ becomes an input; the rest of the text is escaped.
                    $parts = preg_split(BLANK_PATTERN, (string) $q['question_text']);
                    $saved = is_array($saved_value) ? array_values($saved_value) : [];
                    foreach ($parts as $i => $part) {
                        echo nl2br(e($part));
                        if ($i < count($parts) - 1) {
                            echo '<input type="text" class="blank-input" name="' . e($field) . '[]" data-blank value="' . e($saved[$i] ?? '') . '" maxlength="' . BLANK_ANSWER_MAX . '" aria-label="Blank ' . ($i + 1) . ' of question ' . $number . '" autocomplete="off">';
                        }
                    }
                ?></p>
            <?php else: ?>
                <p class="question-text"><?php echo nl2br(e($q['question_text'])); ?></p>
            <?php endif; ?>

            <?php if ($q['type'] === 'mcq' || $q['type'] === 'true_false'): ?>
                <div class="options" role="radiogroup" aria-labelledby="q-label-<?php echo (int) $qid; ?>">
                    <?php foreach (($options_by_question[$qid] ?? []) as $opt):
                        $checked = is_string($saved_value) && $saved_value === (string) $opt['id']; ?>
                        <label class="option-row<?php echo $checked ? ' selected' : ''; ?>">
                            <input type="radio" name="<?php echo e($field); ?>" value="<?php echo (int) $opt['id']; ?>" <?php echo $checked ? 'checked' : ''; ?>>
                            <span><?php echo e($opt['option_text']); ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            <?php elseif ($q['type'] === 'mcq_multi'): ?>
                <p class="helptext">Select all that apply.</p>
                <div class="options" role="group" aria-labelledby="q-label-<?php echo (int) $qid; ?>">
                    <?php $chosen = is_array($saved_value) ? array_map('strval', $saved_value) : [];
                    foreach (($options_by_question[$qid] ?? []) as $opt):
                        $checked = in_array((string) $opt['id'], $chosen, true); ?>
                        <label class="option-row<?php echo $checked ? ' selected' : ''; ?>">
                            <input type="checkbox" name="<?php echo e($field); ?>[]" value="<?php echo (int) $opt['id']; ?>" <?php echo $checked ? 'checked' : ''; ?>>
                            <span><?php echo e($opt['option_text']); ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            <?php elseif ($q['type'] === 'numeric'): ?>
                <label class="field-label" for="answer-<?php echo (int) $qid; ?>">Your answer (a number)</label>
                <input type="text" inputmode="decimal" class="input numeric-input" id="answer-<?php echo (int) $qid; ?>" name="<?php echo e($field); ?>" value="<?php echo e(is_string($saved_value) ? $saved_value : ''); ?>" maxlength="<?php echo NUMERIC_ANSWER_MAX; ?>" autocomplete="off">
            <?php elseif ($q['type'] === 'fill_blank'): ?>
                <p class="helptext">Type your answer in each blank.</p>
            <?php else: ?>
                <label class="visually-hidden" for="answer-<?php echo (int) $qid; ?>">Your answer to question <?php echo $number; ?></label>
                <textarea id="answer-<?php echo (int) $qid; ?>" name="<?php echo e($field); ?>" rows="<?php echo $q['type'] === 'essay' ? 10 : 4; ?>" maxlength="<?php echo $q['type'] === 'essay' ? ESSAY_ANSWER_MAX : TEXT_ANSWER_MAX; ?>" placeholder="<?php echo $q['type'] === 'essay' ? 'Write your essay...' : 'Type your answer...'; ?>" class="answer-box<?php echo $q['type'] === 'essay' ? ' essay-box' : ''; ?>"><?php echo e(is_string($saved_value) ? $saved_value : ''); ?></textarea>
            <?php endif; ?>
            <?php if ($practice): ?>
                <div class="practice-check">
                    <button type="button" class="btn btn-outline btn-sm" data-check="<?php echo (int) $qid; ?>">Check answer</button>
                    <div class="check-result" id="check-<?php echo (int) $qid; ?>" aria-live="polite"></div>
                </div>
            <?php endif; ?>
        </section>
        <?php
    endforeach;
    return (string) ob_get_clean();
}

/**
 * The answer part of a reviewed question (student review and teacher
 * grading), for every question type.
 *
 * @param array $a a row from attempt_review_rows()
 * @param bool $show_key mark correct options and show the accepted answers / explanation
 * @param bool $graded whether to colour the student's answer right/wrong
 * @param string $who 'student' ("your answer") or 'teacher' ("student's answer")
 */
function render_answer_review(array $a, bool $show_key, bool $graded, string $who = 'student'): string
{
    $mine = $who === 'student' ? 'your answer' : "student's answer";
    $none = $who === 'student' ? 'You did not answer this question' : 'No answer given';
    $type = $a['type'];
    ob_start();

    if (in_array($type, OPTION_TYPES, true)) {
        $selected = $type === 'mcq_multi'
            ? array_map('intval', (array) $a['parts'])
            : ($a['selected_option_id'] !== null ? [(int) $a['selected_option_id']] : []);
        echo '<ul class="option-review">';
        foreach ($a['options'] as $opt) {
            $is_selected = in_array((int) $opt['id'], $selected, true);
            $mark_correct = $show_key && $opt['is_correct'];
            $class = '';
            if ($mark_correct) {
                $class = 'is-correct';
            } elseif ($graded && $is_selected) {
                if ($show_key) {
                    $class = 'is-wrong'; // selected and not correct
                } elseif ($type !== 'mcq_multi') {
                    // Key hidden: say only whether the single choice was right.
                    $class = $a['is_correct'] ? 'is-correct' : 'is-wrong';
                }
            }
            echo '<li class="' . $class . '">' . e($opt['option_text'])
                . ($is_selected ? '<span class="tag">' . e($mine) . '</span>' : '')
                . ($mark_correct ? '<span class="tag tag-ok">correct</span>' : '')
                . '</li>';
        }
        if (!$selected) {
            echo '<li class="' . ($graded ? 'is-wrong' : '') . '">' . e($none) . '</li>';
        }
        echo '</ul>';
    } elseif ($type === 'fill_blank') {
        $config = $a['config'];
        $parts = (array) $a['parts'];
        echo '<ul class="blank-review">';
        foreach ($config['blanks'] ?: array_fill(0, max(1, count($parts)), []) as $i => $accepted) {
            $given = (string) ($parts[$i] ?? '');
            $right = $accepted && text_matches($given, $accepted, $config['case_sensitive'], $config['ignore_punctuation']);
            $class = $graded && $show_key ? ($right ? 'is-correct' : 'is-wrong') : '';
            echo '<li class="' . $class . '">Blank ' . ($i + 1) . ': ' . ($given === '' ? '<em>(empty)</em>' : e($given))
                . ($show_key && $accepted ? ' <span class="cell-muted">(accepted: ' . e(implode(' / ', $accepted)) . ')</span>' : '') . '</li>';
        }
        echo '</ul>';
    } else {
        $text = (string) ($a['answer_text'] ?? '');
        echo '<div class="answer-given">' . ($text === '' ? '<em>(no answer given)</em>' : nl2br(e($text))) . '</div>';
        if ($show_key && in_array($type, ['short_answer', 'numeric'], true)) {
            $key = correct_answer_summary(['type' => $type, 'config' => $a['config'], 'options' => []]);
            if ($key !== '') {
                echo '<div class="answer-key"><strong>' . ($type === 'numeric' ? 'Correct value' : 'Accepted answer(s)') . ':</strong> ' . e($key) . '</div>';
            }
        }
    }

    if ($show_key && !empty($a['explanation'])) {
        echo '<div class="explanation-box"><strong>Explanation:</strong> ' . nl2br(e($a['explanation'])) . '</div>';
    }
    return (string) ob_get_clean();
}

/**
 * Questions and options of an attempt in the shape render_quiz_questions()
 * takes, from its snapshot. Correct-answer flags are left out.
 *
 * @return array{0: array<int,array>, 1: array<int,array>}
 */
function quiz_view_data(mysqli $conn, array $attempt): array
{
    $questions = [];
    $options = [];
    foreach (load_attempt_snapshot($conn, $attempt) as $qid => $snap) {
        $questions[$qid] = ['id' => $qid, 'type' => $snap['type'], 'question_text' => $snap['question_text'], 'points' => $snap['points']];
        if (in_array($snap['type'], OPTION_TYPES, true)) {
            $options[$qid] = array_map(fn($o) => ['id' => (int) $o['id'], 'option_text' => $o['text']], $snap['options']);
        }
    }
    return [$questions, $options];
}

/** Score as a percentage with a colour band for pills and bars. */
function score_band(?float $pct): string
{
    if ($pct === null) {
        return '';
    }
    return $pct >= 70 ? 'is-good' : ($pct >= 40 ? 'is-mid' : 'is-low');
}

// ---- Charts (inline SVG, drawn on the server; no chart library) ----------------------

/**
 * Line chart of percentages over time.
 *
 * @param list<array{label:string, value:float, title:string}> $points values 0-100
 */
function svg_line_chart(array $points, string $summary): string
{
    if (count($points) < 2) {
        return '<div class="empty-state">' . ($points ? 'Your trend appears after two graded results.' : 'No graded results yet.') . '</div>';
    }
    $w = 640;
    $h = 220;
    $left = 36;
    $right = 12;
    $top = 12;
    $bottom = 34;
    $pw = $w - $left - $right;
    $ph = $h - $top - $bottom;
    $n = count($points);
    $x = fn($i) => $left + ($n === 1 ? $pw / 2 : $i * $pw / ($n - 1));
    $y = fn($v) => $top + $ph - max(0, min(100, $v)) / 100 * $ph;

    $svg = '<svg class="chart" viewBox="0 0 ' . $w . ' ' . $h . '" role="img" aria-label="' . e($summary) . '">';
    foreach ([0, 25, 50, 75, 100] as $g) {
        $gy = round($y($g), 1);
        $svg .= '<line class="grid" x1="' . $left . '" x2="' . ($w - $right) . '" y1="' . $gy . '" y2="' . $gy . '"/>'
            . '<text x="' . ($left - 6) . '" y="' . ($gy + 4) . '" text-anchor="end">' . $g . '</text>';
    }
    $path = '';
    foreach ($points as $i => $p) {
        $path .= ($i ? ' L' : 'M') . round($x($i), 1) . ' ' . round($y($p['value']), 1);
    }
    $svg .= '<path class="line" d="' . $path . '"/>';
    $step = max(1, (int) ceil($n / 8));
    foreach ($points as $i => $p) {
        $svg .= '<circle class="dot" cx="' . round($x($i), 1) . '" cy="' . round($y($p['value']), 1) . '" r="4"><title>' . e($p['title']) . '</title></circle>';
        if ($i % $step === 0 || $i === $n - 1) {
            $svg .= '<text x="' . round($x($i), 1) . '" y="' . ($h - 12) . '" text-anchor="middle">' . e($p['label']) . '</text>';
        }
    }
    $svg .= '</svg>';
    // The same data as a table for screen readers.
    $table = '<table class="visually-hidden"><caption>' . e($summary) . '</caption><tr><th>Date</th><th>Score</th></tr>';
    foreach ($points as $p) {
        $table .= '<tr><td>' . e($p['title']) . '</td><td>' . e((string) $p['value']) . '%</td></tr>';
    }
    return $svg . $table . '</table>';
}

/**
 * Vertical bars (e.g. a score distribution). Bars are coloured by band when
 * $band_by_index is true (the first bars low, the last high).
 *
 * @param array<string,int> $bars label => count
 */
function svg_bar_chart(array $bars, string $summary, bool $band_by_index = true): string
{
    $max = max(1, max($bars ?: [0]));
    $w = 640;
    $h = 220;
    $left = 30;
    $bottom = 34;
    $top = 14;
    $n = max(1, count($bars));
    $slot = ($w - $left - 8) / $n;
    $bw = max(6, $slot * 0.7);
    $ph = $h - $top - $bottom;
    $svg = '<svg class="chart" viewBox="0 0 ' . $w . ' ' . $h . '" role="img" aria-label="' . e($summary) . '">';
    $svg .= '<line class="axis" x1="' . $left . '" x2="' . ($w - 8) . '" y1="' . ($top + $ph) . '" y2="' . ($top + $ph) . '"/>';
    $i = 0;
    foreach ($bars as $label => $count) {
        $bh = $count / $max * $ph;
        $bx = $left + $i * $slot + ($slot - $bw) / 2;
        $band = '';
        if ($band_by_index) {
            $band = $i < $n * 0.4 ? ' is-low' : ($i < $n * 0.7 ? ' is-mid' : '');
        }
        $svg .= '<rect class="bar' . $band . '" x="' . round($bx, 1) . '" y="' . round($top + $ph - $bh, 1) . '" width="' . round($bw, 1) . '" height="' . round($bh, 1) . '"><title>' . e($label . ': ' . $count) . '</title></rect>';
        if ($count > 0) {
            $svg .= '<text x="' . round($bx + $bw / 2, 1) . '" y="' . round($top + $ph - $bh - 4, 1) . '" text-anchor="middle">' . (int) $count . '</text>';
        }
        $svg .= '<text x="' . round($bx + $bw / 2, 1) . '" y="' . ($h - 12) . '" text-anchor="middle">' . e($label) . '</text>';
        $i++;
    }
    return $svg . '</svg>';
}

/**
 * Horizontal percentage bars: name, bar, value. Rows: [name, pct, meta?].
 */
function bar_list(array $rows, string $label): string
{
    if (!$rows) {
        return '<div class="empty-state">No data yet.</div>';
    }
    $html = '<div class="bar-list" role="list" aria-label="' . e($label) . '">';
    foreach ($rows as $r) {
        $pct = $r['pct'] === null ? 0 : (float) $r['pct'];
        $html .= '<div class="bar-list-row" role="listitem">'
            . '<span>' . e($r['name']) . (isset($r['meta']) ? ' <span class="cell-muted">' . e($r['meta']) . '</span>' : '') . '</span>'
            . '<span class="bar-track"><span class="bar-fill ' . score_band($pct) . '" style="width:' . round(max(0, min(100, $pct)), 1) . '%"></span></span>'
            . '<span class="bar-value">' . ($r['pct'] === null ? '-' : e((string) round($pct)) . '%') . '</span></div>';
    }
    return $html . '</div>';
}
