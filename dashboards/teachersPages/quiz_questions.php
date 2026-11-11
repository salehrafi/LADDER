<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once APP_ROOT . '/includes/question_import.php';

require_role('teacher', '../../auth/login.php');

if (isset($_GET['sample_md'])) {
    $all = $_GET['sample_md'] === 'all';
    header('Content-Type: text/markdown; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . ($all ? 'sample-all-question-types.md' : 'sample-questions.md') . '"');
    echo $all ? sample_question_markdown_all_types() : sample_question_markdown();
    exit;
}

$teacher_email = current_user_email();
$quiz_id = (int) ($_GET['quiz_id'] ?? $_POST['quiz_id'] ?? 0);
$success = "";
$error = "";
$import_text = "";        // Markdown kept in the paste box between preview and import
$import_errors = [];
$import_preview = null;   // parsed questions, shown before importing

// Ownership check: a teacher can only open their own quizzes.
$stmt = $conn->prepare("SELECT * FROM quizzes WHERE id = ? AND teacher_email = ?");
$stmt->bind_param("is", $quiz_id, $teacher_email);
$stmt->execute();
$quiz = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$quiz) {
    header("Location: setQuiz.php");
    exit;
}

// --- Handle actions --------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    $page_url = "quiz_questions.php?quiz_id=$quiz_id";

    if ($action === 'add_question' || $action === 'update_question') {
        $editing_id = $action === 'update_question' ? (int) ($_POST['question_id'] ?? 0) : 0;
        $existing = $editing_id ? find_teacher_question($conn, $teacher_email, $editing_id) : null;
        if ($editing_id && (!$existing || (int) $existing['quiz_id'] !== $quiz_id)) {
            flash('error', 'That question is not in this quiz.');
            redirect($page_url);
        }
        [$data, $form_errors, $form] = question_from_form($conn, $teacher_email, $_POST);
        if ($data) {
            save_question($conn, $teacher_email, $data, $editing_id ?: null, $quiz_id);
            flash('success', $editing_id
                ? 'Question updated. Attempts already started keep the version they were taken with.'
                : 'Question added.');
            redirect($page_url . ($editing_id ? '#question-list' : '#question-editor'));
        }
        $form['id'] = $editing_id;
        $error = 'The question was not saved.';
    } elseif ($action === 'save_to_bank') {
        $question_id = (int) ($_POST['question_id'] ?? 0);
        $q = find_teacher_question($conn, $teacher_email, $question_id);
        if ($q && (int) $q['quiz_id'] === $quiz_id) {
            copy_question($conn, $teacher_email, $question_id, null);
            flash('success', 'A copy was saved to your question bank.');
        }
        redirect($page_url . '#question-list');
    } elseif ($action === 'add_from_bank') {
        $ids = array_map('intval', is_array($_POST['bank_ids'] ?? null) ? $_POST['bank_ids'] : []);
        $added = 0;
        foreach (array_unique($ids) as $bank_id) {
            $q = find_teacher_question($conn, $teacher_email, $bank_id);
            if ($q && $q['quiz_id'] === null) {
                copy_question($conn, $teacher_email, $bank_id, $quiz_id);
                $added++;
            }
        }
        flash($added ? 'success' : 'warning', $added ? "$added question(s) added from your bank." : 'Tick the questions you want to add.');
        redirect($page_url . '#question-list');
    } elseif ($action === 'import_markdown') {
        // Source: an uploaded .md file if one was chosen, otherwise the paste box.
        $file = $_FILES['md_file'] ?? null;
        $has_file = $file && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        if ($has_file) {
            $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
            if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
                $error = "The file could not be uploaded. Try again.";
            } elseif ($file['size'] > MD_IMPORT_MAX_BYTES) {
                $error = "The file is too large (max 512 KB).";
            } elseif (!in_array($ext, ['md', 'markdown', 'txt'], true)) {
                $error = "Upload a Markdown file (.md or .txt).";
            } else {
                $import_text = (string) file_get_contents($file['tmp_name']);
            }
        } else {
            $import_text = (string) ($_POST['markdown'] ?? '');
            if (strlen($import_text) > MD_IMPORT_MAX_BYTES) {
                $error = "The text is too long (max 512 KB).";
                $import_text = '';
            }
        }

        if ($error === '' && trim($import_text) === '') {
            $error = "Upload the Markdown file from the AI, or paste its text into the box.";
        } elseif ($error === '') {
            $parsed = parse_question_markdown($import_text);
            $import_errors = $parsed['errors'];
            if ($import_errors) {
                $error = "Nothing was imported. Fix the problems below (or ask the AI to fix them) and try again.";
            } elseif (($_POST['mode'] ?? '') === 'import') {
                $added = import_questions($conn, $quiz_id, $parsed['questions']);
                header("Location: quiz_questions.php?quiz_id=$quiz_id&imported=$added");
                exit;
            } else {
                $import_preview = $parsed['questions'];
            }
        }
    } elseif ($action === 'delete_question') {
        $question_id = (int) ($_POST['question_id'] ?? 0);
        $q = find_teacher_question($conn, $teacher_email, $question_id);
        if ($q && (int) $q['quiz_id'] === $quiz_id) {
            $result = remove_question($conn, $teacher_email, $question_id);
            flash('success', $result === 'hidden'
                ? 'Question removed from the quiz. Past attempts keep it, with their answers and grades.'
                : 'Question deleted.');
        }
        redirect($page_url . '#question-list');
    } elseif ($action === 'move_question') {
        $question_id = (int) ($_POST['question_id'] ?? 0);
        $direction = $_POST['direction'] ?? '';

        $stmt = $conn->prepare("SELECT id, order_index FROM questions WHERE quiz_id = ? AND deleted_at IS NULL ORDER BY order_index ASC");
        $stmt->bind_param("i", $quiz_id);
        $stmt->execute();
        $ordered = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $pos = array_search($question_id, array_column($ordered, 'id'));
        if ($pos !== false) {
            $swap_pos = $direction === 'up' ? $pos - 1 : $pos + 1;
            if ($swap_pos >= 0 && $swap_pos < count($ordered)) {
                $a = $ordered[$pos];
                $b = $ordered[$swap_pos];
                $upd = $conn->prepare("UPDATE questions SET order_index = ? WHERE id = ?");
                $upd->bind_param("ii", $b['order_index'], $a['id']);
                $upd->execute();
                $upd->bind_param("ii", $a['order_index'], $b['id']);
                $upd->execute();
                $upd->close();
            }
        }
    }
}

// --- Load questions ----------------------------------------------------
$stmt = $conn->prepare(
    "SELECT q.*, t.name AS topic_name FROM questions q LEFT JOIN topics t ON t.id = q.topic_id
     WHERE q.quiz_id = ? AND q.deleted_at IS NULL ORDER BY q.order_index ASC, q.id ASC"
);
$stmt->bind_param("i", $quiz_id);
$stmt->execute();
$questions = attach_options($conn, $stmt->get_result()->fetch_all(MYSQLI_ASSOC));
$stmt->close();

$catalog = teacher_catalog($conn, $teacher_email);

// Editor: a new question, an existing one (?edit=ID), or the form re-shown with errors.
if (!isset($form)) {
    $form = question_form_defaults();
    $edit_id = (int) ($_GET['edit'] ?? 0);
    if ($edit_id) {
        $editing = find_teacher_question($conn, $teacher_email, $edit_id);
        if ($editing && (int) $editing['quiz_id'] === $quiz_id) {
            $form = question_form_values($editing);
        }
    }
}
$form_errors = $form_errors ?? [];

// Bank picker filters (GET).
$bank_filters = [
    'q' => trim((string) ($_GET['bank_q'] ?? '')),
    'topic_id' => (int) ($_GET['bank_topic'] ?? 0),
    'type' => (string) ($_GET['bank_type'] ?? ''),
    'difficulty' => (string) ($_GET['bank_difficulty'] ?? ''),
];
$bank = bank_questions($conn, $teacher_email, $bank_filters, (int) ($_GET['bank_page'] ?? 1), 10);
$bank_open = isset($_GET['bank_q']) || isset($_GET['bank_page']);

$total_points = array_sum(array_column($questions, 'points'));

$active = 'quizzes';
$nav_base = '../';
$is_new = isset($_GET['new']);
if (isset($_GET['imported'])) {
    $success = (int) $_GET['imported'] . " question(s) imported. Check the answers below before you publish.";
}
$prompt_defaults = question_prompt_defaults();
$show_import = $is_new || empty($questions) || $import_text !== '' || isset($_GET['import']);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../navStyle.css?v=9">
    <link rel="stylesheet" href="../appStyle.css?v=9">
    <title>Questions - <?php echo e($quiz['title']); ?></title>
</head>

<body>
    <?php include APP_ROOT . '/dashboards/partials/nav_teacher.php'; ?>

    <div class="container" id="main">
        <div class="page-header">
            <div>
                <h1><?php echo e($quiz['title']); ?></h1>
                <p class="subtitle">
                    <span class="badge badge-<?php echo e($quiz['status']); ?>"><?php echo ucfirst(e($quiz['status'])); ?></span>
                    &nbsp;<?php echo count($questions); ?> question(s) &middot; <?php echo fmt_score($total_points); ?> point(s) total
                </p>
            </div>
            <a href="setQuiz.php" class="btn btn-outline">&larr; Back to Quizzes</a>
        </div>

        <?php if ($is_new): ?>
            <div class="alert alert-info">Quiz created as a draft. Add your questions below, then publish it from the Manage Quizzes page.</div>
        <?php endif; ?>
        <?php if ($success): ?><div class="alert alert-success"><?php echo e($success); ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-error"><?php echo e($error); ?></div><?php endif; ?>

        <details class="card ai-import" id="ai-import" <?php echo $show_import ? 'open' : ''; ?>>
            <summary><h2>Generate questions with AI</h2><span class="helptext">Write a prompt, let ChatGPT, Claude or Gemini write the questions, upload the file.</span></summary>

            <div class="ai-step">
                <h3><span class="step-num">1</span> Describe the quiz</h3>
                <div class="form-grid prompt-fields">
                    <div class="form-field">
                        <label for="p-topic">Topic</label>
                        <input type="text" id="p-topic" data-key="topic" value="<?php echo e($prompt_defaults['topic']); ?>">
                    </div>
                    <div class="form-field">
                        <label for="p-level">Class / level</label>
                        <input type="text" id="p-level" data-key="level" value="<?php echo e($prompt_defaults['level']); ?>">
                    </div>
                    <div class="form-field">
                        <label for="p-difficulty">Difficulty</label>
                        <select id="p-difficulty" data-key="difficulty">
                            <?php foreach (['easy', 'medium', 'hard', 'mixed'] as $d): ?>
                                <option value="<?php echo $d; ?>" <?php echo $d === $prompt_defaults['difficulty'] ? 'selected' : ''; ?>><?php echo ucfirst($d); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-field">
                        <label for="p-language">Language</label>
                        <input type="text" id="p-language" data-key="language" value="<?php echo e($prompt_defaults['language']); ?>">
                    </div>
                    <div class="form-field">
                        <label for="p-mcq">Multiple-choice questions</label>
                        <input type="number" id="p-mcq" data-key="mcq" min="0" max="100" value="<?php echo $prompt_defaults['mcq']; ?>">
                    </div>
                    <div class="form-field">
                        <label for="p-mcq-points">Points each (MCQ)</label>
                        <input type="number" id="p-mcq-points" data-key="mcq_points" min="0.25" step="0.25" value="<?php echo $prompt_defaults['mcq_points']; ?>">
                    </div>
                    <div class="form-field">
                        <label for="p-short">Short-answer questions</label>
                        <input type="number" id="p-short" data-key="short" min="0" max="100" value="<?php echo $prompt_defaults['short']; ?>">
                    </div>
                    <div class="form-field">
                        <label for="p-short-points">Points each (short)</label>
                        <input type="number" id="p-short-points" data-key="short_points" min="0.25" step="0.25" value="<?php echo $prompt_defaults['short_points']; ?>">
                    </div>
                </div>
                <div class="form-field">
                    <label for="p-extra">Extra instructions (optional)</label>
                    <input type="text" id="p-extra" data-key="extra" placeholder="e.g. focus on chapter 3, include two numerical problems">
                </div>
            </div>

            <div class="ai-step">
                <h3><span class="step-num">2</span> Copy the prompt into an AI chat</h3>
                <textarea id="ai-prompt" class="prompt-box" rows="12" spellcheck="false" aria-label="Prompt for the AI"><?php echo e(build_question_prompt()); ?></textarea>
                <p class="helptext">You can edit the prompt directly. Changing a field above rebuilds it.</p>
                <div class="row-actions">
                    <button type="button" class="btn" id="copy-prompt">Copy prompt</button>
                    <a class="btn btn-outline" href="https://chatgpt.com/" target="_blank" rel="noopener">ChatGPT</a>
                    <a class="btn btn-outline" href="https://claude.ai/new" target="_blank" rel="noopener">Claude</a>
                    <a class="btn btn-outline" href="https://gemini.google.com/app" target="_blank" rel="noopener">Gemini</a>
                </div>
            </div>

            <div class="ai-step">
                <h3><span class="step-num">3</span> Upload or paste the AI's answer</h3>
                <p class="helptext">Save the reply as a <code>.md</code> file and upload it, or copy the reply and paste it below.
                    <a href="quiz_questions.php?quiz_id=<?php echo $quiz_id; ?>&amp;sample_md=1">Download a sample file</a> to see the format, or <a href="quiz_questions.php?quiz_id=<?php echo $quiz_id; ?>&amp;sample_md=all">a sample with every question type</a> (true/false, multiple select, numerical, fill in the blanks, essay).</p>
                <form method="POST" enctype="multipart/form-data" id="import-form"><?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="import_markdown">
                    <input type="hidden" name="quiz_id" value="<?php echo $quiz_id; ?>">
                    <div class="form-field">
                        <label for="md_file">Markdown file</label>
                        <input type="file" id="md_file" name="md_file" accept=".md,.markdown,.txt,text/markdown,text/plain">
                    </div>
                    <div class="form-field">
                        <label for="markdown">…or paste it here</label>
                        <textarea id="markdown" name="markdown" rows="8" class="prompt-box" spellcheck="false" placeholder="## Q1 [mcq] (1 point)&#10;Question text&#10;- [ ] wrong option&#10;- [x] correct option"><?php echo e($import_text); ?></textarea>
                    </div>
                    <?php if ($import_errors): ?>
                        <ul class="import-errors">
                            <?php foreach ($import_errors as $msg): ?><li><?php echo e($msg); ?></li><?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <div class="row-actions">
                        <button type="submit" name="mode" value="preview" class="btn btn-outline">Preview</button>
                        <button type="submit" name="mode" value="import" class="btn">Import questions</button>
                    </div>
                </form>
            </div>

            <?php if ($import_preview !== null): ?>
                <div class="ai-step md-preview">
                    <h3>Preview: <?php echo count($import_preview); ?> question(s), <?php echo fmt_score(array_sum(array_column($import_preview, 'points'))); ?> point(s)</h3>
                    <p class="helptext">Nothing is saved yet. Check the correct answers, then press <strong>Import questions</strong>.</p>
                    <?php foreach ($import_preview as $idx => $q): ?>
                        <div class="question-block">
                            <span class="q-number">Q<?php echo $idx + 1; ?>.</span>
                            <span class="points-tag"><?php echo fmt_score($q['points']); ?> pt<?php echo $q['points'] == 1 ? '' : 's'; ?></span>
                            <span class="badge badge-info"><?php echo e(question_type_label($q['type'])); ?></span>
                            <p class="question-text"><?php echo nl2br(e($q['text'])); ?></p>
                            <?php if ($q['options']):
                                $right = $q['correct_list'] ?? [$q['correct']]; ?>
                                <ul class="preview-options">
                                    <?php foreach ($q['options'] as $i => $opt): $ok = in_array($i, $right, true); ?>
                                        <li class="<?php echo $ok ? 'is-correct' : ''; ?>"><?php echo e($opt); ?><?php echo $ok ? ' ✓ correct' : ''; ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php elseif ($q['type'] === 'essay'): ?>
                                <p class="helptext">Essay: you grade it by hand.</p>
                            <?php else:
                                $summary = correct_answer_summary(['type' => $q['type'], 'config' => $q['config'] ?? null, 'model_answer' => $q['answer'], 'options' => []]); ?>
                                <p class="helptext">Answer: <?php echo $summary === '' ? '<em>none, you grade it by hand</em>' : e($summary); ?></p>
                            <?php endif; ?>
                            <?php if (!empty($q['explanation'])): ?><p class="helptext">Explanation: <?php echo e($q['explanation']); ?></p><?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    <button type="submit" form="import-form" name="mode" value="import" class="btn">Import these <?php echo count($import_preview); ?> questions</button>
                </div>
            <?php endif; ?>
        </details>

        <div class="card" id="question-list">
            <div class="card-header">
                <h2>Questions (<?php echo count($questions); ?>)</h2>
                <a class="link" href="question_bank.php">Open question bank &rarr;</a>
            </div>
            <?php if (empty($questions)): ?>
                <div class="empty-state">No questions yet. Add one below, pick from your bank, or generate them with AI.</div>
            <?php else: ?>
                <?php foreach ($questions as $idx => $q): ?>
                    <div class="question-block">
                        <div class="question-summary">
                            <div>
                                <span class="q-number">Q<?php echo $idx + 1; ?></span>
                                <span class="points-tag"><?php echo fmt_score($q['points']); ?> pt<?php echo (float) $q['points'] == 1 ? '' : 's'; ?></span>
                                <span class="badge badge-info"><?php echo e(question_type_label($q['type'])); ?></span>
                                <?php if ($q['difficulty']): ?><span class="badge badge-draft"><?php echo e(DIFFICULTIES[$q['difficulty']] ?? $q['difficulty']); ?></span><?php endif; ?>
                                <?php if ($q['topic_name']): ?><span class="badge badge-draft"><?php echo e($q['topic_name']); ?></span><?php endif; ?>
                                <p class="question-text"><?php echo nl2br(e($q['question_text'])); ?></p>
                            </div>
                            <div class="item-actions">
                                <a class="btn btn-outline btn-sm" href="quiz_questions.php?quiz_id=<?php echo $quiz_id; ?>&amp;edit=<?php echo (int) $q['id']; ?>#question-editor">Edit</a>
                                <form method="POST"><?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="save_to_bank">
                                    <input type="hidden" name="quiz_id" value="<?php echo $quiz_id; ?>">
                                    <input type="hidden" name="question_id" value="<?php echo (int) $q['id']; ?>">
                                    <button type="submit" class="btn btn-outline btn-sm">Save to bank</button>
                                </form>
                                <form method="POST"><?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="move_question">
                                    <input type="hidden" name="quiz_id" value="<?php echo $quiz_id; ?>">
                                    <input type="hidden" name="question_id" value="<?php echo (int) $q['id']; ?>">
                                    <input type="hidden" name="direction" value="up">
                                    <button type="submit" class="btn btn-outline btn-sm" aria-label="Move question <?php echo $idx + 1; ?> up" <?php echo $idx === 0 ? 'disabled' : ''; ?>>&uarr;</button>
                                </form>
                                <form method="POST"><?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="move_question">
                                    <input type="hidden" name="quiz_id" value="<?php echo $quiz_id; ?>">
                                    <input type="hidden" name="question_id" value="<?php echo (int) $q['id']; ?>">
                                    <input type="hidden" name="direction" value="down">
                                    <button type="submit" class="btn btn-outline btn-sm" aria-label="Move question <?php echo $idx + 1; ?> down" <?php echo $idx === count($questions) - 1 ? 'disabled' : ''; ?>>&darr;</button>
                                </form>
                                <form method="POST" data-confirm="Delete this question? Students who already answered it keep their answers and grades." data-danger><?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="delete_question">
                                    <input type="hidden" name="quiz_id" value="<?php echo $quiz_id; ?>">
                                    <input type="hidden" name="question_id" value="<?php echo (int) $q['id']; ?>">
                                    <button type="submit" class="btn btn-danger-outline btn-sm">Delete</button>
                                </form>
                            </div>
                        </div>

                        <?php if (in_array($q['type'], OPTION_TYPES, true)): ?>
                            <ul class="option-list">
                                <?php foreach ($q['options'] as $opt): ?>
                                    <li<?php echo $opt['is_correct'] ? ' class="is-correct"' : ''; ?>><?php echo e($opt['option_text']); ?><?php echo $opt['is_correct'] ? ' ✓ correct' : ''; ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php elseif (correct_answer_summary($q) !== ''): ?>
                            <p class="helptext"><strong>Answer:</strong> <?php echo e(correct_answer_summary($q)); ?></p>
                        <?php endif; ?>
                        <?php if (grading_rule_summary($q) !== ''): ?><p class="helptext"><?php echo e(grading_rule_summary($q)); ?></p><?php endif; ?>
                        <?php if (!empty($q['explanation'])): ?><div class="explanation-box"><strong>Explanation:</strong> <?php echo nl2br(e($q['explanation'])); ?></div><?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="card" id="question-editor">
            <h2><?php echo $form['id'] ? 'Edit question' : 'Add a question'; ?></h2>
            <?php if ($form['id']): ?>
                <p class="helptext">Changes apply to attempts started from now on. Submitted attempts keep the version they were graded with; use <strong>Re-grade</strong> on the Results page to apply a corrected key to them.</p>
            <?php endif; ?>
            <?php
            $form_action = $form['id'] ? 'update_question' : 'add_question';
            $form_hidden = ['quiz_id' => $quiz_id] + ($form['id'] ? ['question_id' => $form['id']] : []);
            $form_submit = $form['id'] ? 'Save changes' : 'Add question';
            $form_cancel = $form['id'] ? "quiz_questions.php?quiz_id=$quiz_id#question-list" : '';
            include APP_ROOT . '/dashboards/partials/question_form.php';
            ?>
        </div>

        <details class="card ai-import" id="bank-picker" <?php echo $bank_open ? 'open' : ''; ?>>
            <summary><h2>Add from your question bank</h2><span class="helptext">Reuse questions you have saved. Each is copied into this quiz.</span></summary>
            <form method="GET" class="filter-bar" action="quiz_questions.php#bank-picker">
                <input type="hidden" name="quiz_id" value="<?php echo $quiz_id; ?>">
                <input type="search" name="bank_q" value="<?php echo e($bank_filters['q']); ?>" placeholder="Search question text" aria-label="Search the bank">
                <select name="bank_topic" aria-label="Topic">
                    <option value="0">All topics</option>
                    <?php foreach ($catalog as $subject): foreach ($subject['topics'] as $t): ?>
                        <option value="<?php echo (int) $t['id']; ?>" <?php echo $bank_filters['topic_id'] === (int) $t['id'] ? 'selected' : ''; ?>><?php echo e($subject['name'] . ': ' . $t['name']); ?></option>
                    <?php endforeach; endforeach; ?>
                </select>
                <select name="bank_type" aria-label="Type">
                    <option value="">All types</option>
                    <?php foreach (QUESTION_TYPES as $value => $label): ?>
                        <option value="<?php echo e($value); ?>" <?php echo $bank_filters['type'] === $value ? 'selected' : ''; ?>><?php echo e($label); ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="bank_difficulty" aria-label="Difficulty">
                    <option value="">Any difficulty</option>
                    <?php foreach (DIFFICULTIES as $value => $label): ?>
                        <option value="<?php echo e($value); ?>" <?php echo $bank_filters['difficulty'] === $value ? 'selected' : ''; ?>><?php echo e($label); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-outline">Filter</button>
            </form>
            <?php if (!$bank['rows']): ?>
                <div class="empty-state">No bank questions match. Save questions to the bank from any quiz, or create them in the <a class="link" href="question_bank.php">question bank</a>.</div>
            <?php else: ?>
                <form method="POST"><?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="add_from_bank">
                    <input type="hidden" name="quiz_id" value="<?php echo $quiz_id; ?>">
                    <div class="card-list">
                        <?php foreach ($bank['rows'] as $b): ?>
                            <label class="item-row" style="cursor:pointer">
                                <span class="form-check" style="align-items:flex-start">
                                    <input type="checkbox" name="bank_ids[]" value="<?php echo (int) $b['id']; ?>">
                                    <span>
                                        <span class="item-title"><?php echo e(mb_strimwidth($b['question_text'], 0, 140, '…')); ?></span>
                                        <span class="item-meta" style="display:block"><?php echo e(question_type_label($b['type'])); ?> · <?php echo fmt_score($b['points']); ?> pt<?php echo $b['topic_name'] ? ' · ' . e($b['subject_name'] . ': ' . $b['topic_name']) : ''; ?><?php echo $b['difficulty'] ? ' · ' . e(DIFFICULTIES[$b['difficulty']] ?? '') : ''; ?></span>
                                    </span>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <div class="item-actions is-spaced">
                        <button type="submit" class="btn">Add selected to this quiz</button>
                        <?php if ($bank['pages'] > 1): ?>
                            <span class="cell-muted">Page <?php echo $bank['page']; ?> of <?php echo $bank['pages']; ?></span>
                            <?php $qs = http_build_query(['quiz_id' => $quiz_id, 'bank_q' => $bank_filters['q'], 'bank_topic' => $bank_filters['topic_id'], 'bank_type' => $bank_filters['type'], 'bank_difficulty' => $bank_filters['difficulty']]); ?>
                            <?php if ($bank['page'] > 1): ?><a class="btn btn-outline btn-sm" href="quiz_questions.php?<?php echo e($qs); ?>&amp;bank_page=<?php echo $bank['page'] - 1; ?>#bank-picker">Previous</a><?php endif; ?>
                            <?php if ($bank['page'] < $bank['pages']): ?><a class="btn btn-outline btn-sm" href="quiz_questions.php?<?php echo e($qs); ?>&amp;bank_page=<?php echo $bank['page'] + 1; ?>#bank-picker">Next</a><?php endif; ?>
                        <?php endif; ?>
                    </div>
                </form>
            <?php endif; ?>
        </details>
    </div>

    <script>
        // Prompt builder: fills the same template the server uses (build_question_prompt).
        (function () {
            const template = <?php echo json_encode(question_prompt_template(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>;
            const defaults = <?php echo json_encode($prompt_defaults, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>;
            const box = document.getElementById('ai-prompt');
            const fields = document.querySelectorAll('.ai-import [data-key]');

            function build() {
                const v = Object.assign({}, defaults);
                fields.forEach(f => { if (f.value.trim() !== '') v[f.dataset.key] = f.value.trim(); });
                let text = v.extra === '' ? template.replace('{{extra}}\n', '')
                                          : template.replace('{{extra}}', 'Extra instructions: ' + v.extra);
                Object.keys(v).forEach(k => { text = text.split('{{' + k + '}}').join(String(v[k])); });
                box.value = text;
            }
            fields.forEach(f => f.addEventListener('input', build));

            const copy = document.getElementById('copy-prompt');
            copy.addEventListener('click', async function () {
                try {
                    await navigator.clipboard.writeText(box.value);
                } catch (err) {
                    box.select();
                    document.execCommand('copy');
                }
                copy.textContent = 'Copied!';
                setTimeout(() => { copy.textContent = 'Copy prompt'; }, 2000);
            });

            // A chosen file wins over the paste box; say so instead of silently ignoring the text.
            document.getElementById('md_file').addEventListener('change', function () {
                document.getElementById('markdown').placeholder = this.files.length ? 'The uploaded file will be used.' : '';
            });
        })();

    </script>
</body>

</html>
