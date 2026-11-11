<?php
/**
 * Question bank: the teacher's reusable questions, organised by subject,
 * topic, type and difficulty. Questions are copied into quizzes, so editing
 * the bank never changes a quiz that already uses a question.
 */
require_once __DIR__ . '/../../includes/bootstrap.php';

require_role('teacher', '../../auth/login.php');

$teacher_email = current_user_email();
$error = '';

$filters = [
    'q' => trim((string) ($_GET['q'] ?? '')),
    'subject_id' => (int) ($_GET['subject'] ?? 0),
    'topic_id' => (int) ($_GET['topic'] ?? 0),
    'type' => (string) ($_GET['type'] ?? ''),
    'difficulty' => (string) ($_GET['difficulty'] ?? ''),
];
$list_query = http_build_query(array_filter([
    'q' => $filters['q'], 'subject' => $filters['subject_id'] ?: null, 'topic' => $filters['topic_id'] ?: null,
    'type' => $filters['type'], 'difficulty' => $filters['difficulty'],
]));
$back = 'question_bank.php' . ($list_query ? "?$list_query" : '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'add_question' || $action === 'update_question') {
            $editing_id = $action === 'update_question' ? (int) ($_POST['question_id'] ?? 0) : 0;
            $existing = $editing_id ? find_teacher_question($conn, $teacher_email, $editing_id) : null;
            if ($editing_id && (!$existing || $existing['quiz_id'] !== null)) {
                flash('error', 'Question not found.');
                redirect($back);
            }
            [$data, $form_errors, $form] = question_from_form($conn, $teacher_email, $_POST);
            if ($data) {
                save_question($conn, $teacher_email, $data, $editing_id ?: null, null);
                flash('success', $editing_id ? 'Question updated. Quizzes that already use it keep their own copy.' : 'Question added to your bank.');
                redirect($back);
            }
            $form['id'] = $editing_id;
            $show_editor = true;
        } elseif ($action === 'delete_question') {
            $id = (int) ($_POST['question_id'] ?? 0);
            $q = find_teacher_question($conn, $teacher_email, $id);
            if ($q && $q['quiz_id'] === null) {
                remove_question($conn, $teacher_email, $id);
                flash('success', 'Question deleted from your bank. Quizzes that use a copy are not affected.');
            }
            redirect($back);
        } elseif ($action === 'add_to_quiz') {
            $quiz_id = (int) ($_POST['quiz_id'] ?? 0);
            $ids = array_map('intval', is_array($_POST['ids'] ?? null) ? $_POST['ids'] : []);
            $stmt = $conn->prepare("SELECT id, title FROM quizzes WHERE id = ? AND teacher_email = ?");
            $stmt->bind_param("is", $quiz_id, $teacher_email);
            $stmt->execute();
            $quiz = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$quiz) {
                flash('error', 'Choose one of your quizzes.');
                redirect($back);
            }
            $added = 0;
            foreach (array_unique($ids) as $id) {
                $q = find_teacher_question($conn, $teacher_email, $id);
                if ($q && $q['quiz_id'] === null) {
                    copy_question($conn, $teacher_email, $id, $quiz_id);
                    $added++;
                }
            }
            flash($added ? 'success' : 'warning', $added ? "$added question(s) added to \"{$quiz['title']}\"." : 'Tick the questions you want to add.');
            redirect($back);
        } elseif ($action === 'create_subject') {
            create_subject($conn, $teacher_email, (string) ($_POST['name'] ?? ''));
            flash('success', 'Subject saved.');
            redirect($back . '#catalog');
        } elseif ($action === 'create_topic') {
            create_topic($conn, $teacher_email, (int) ($_POST['subject_id'] ?? 0), (string) ($_POST['name'] ?? ''));
            flash('success', 'Topic saved.');
            redirect($back . '#catalog');
        } elseif ($action === 'delete_subject') {
            delete_subject($conn, $teacher_email, (int) ($_POST['subject_id'] ?? 0));
            flash('success', 'Subject deleted. Its questions are kept, without a topic.');
            redirect($back . '#catalog');
        } elseif ($action === 'delete_topic') {
            delete_topic($conn, $teacher_email, (int) ($_POST['topic_id'] ?? 0));
            flash('success', 'Topic deleted. Its questions are kept, without a topic.');
            redirect($back . '#catalog');
        }
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    }
}

$catalog = teacher_catalog($conn, $teacher_email);
$result = bank_questions($conn, $teacher_email, $filters, (int) ($_GET['page'] ?? 1));

if (!isset($form)) {
    $form = question_form_defaults();
    $edit_id = (int) ($_GET['edit'] ?? 0);
    if ($edit_id) {
        $q = find_teacher_question($conn, $teacher_email, $edit_id);
        if ($q && $q['quiz_id'] === null) {
            $form = question_form_values($q);
        }
    }
}
$form_errors = $form_errors ?? [];
$show_editor = $show_editor ?? (isset($_GET['new']) || $form['id']);

$stmt = $conn->prepare("SELECT id, title FROM quizzes WHERE teacher_email = ? ORDER BY created_at DESC");
$stmt->bind_param("s", $teacher_email);
$stmt->execute();
$my_quizzes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$active = 'bank';
$nav_base = '../';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../navStyle.css?v=9">
    <link rel="stylesheet" href="../appStyle.css?v=10">
    <title>Question Bank · LADDER</title>
</head>

<body>
    <?php include APP_ROOT . '/dashboards/partials/nav_teacher.php'; ?>

    <div class="container" id="main">
        <div class="page-header">
            <div>
                <h1>Question Bank</h1>
                <p class="subtitle">Create, organise and reuse questions across your quizzes. <?php echo (int) $result['total']; ?> question(s) match.</p>
            </div>
            <div class="item-actions">
                <a href="#catalog" class="btn btn-outline">Subjects &amp; topics</a>
                <a href="question_bank.php?new=1#question-editor" class="btn">+ New question</a>
            </div>
        </div>

        <?php if ($error): ?><div class="alert alert-error" role="alert"><?php echo e($error); ?></div><?php endif; ?>

        <?php if ($show_editor): ?>
            <div class="card" id="question-editor">
                <h2><?php echo $form['id'] ? 'Edit bank question' : 'New bank question'; ?></h2>
                <?php
                $form_action = $form['id'] ? 'update_question' : 'add_question';
                $form_hidden = $form['id'] ? ['question_id' => $form['id']] : [];
                $form_submit = $form['id'] ? 'Save changes' : 'Add to bank';
                $form_cancel = $back;
                include APP_ROOT . '/dashboards/partials/question_form.php';
                ?>
            </div>
        <?php endif; ?>

        <div class="card">
            <form method="GET" class="filter-bar" action="question_bank.php" role="search">
                <input type="search" name="q" value="<?php echo e($filters['q']); ?>" placeholder="Search question text" aria-label="Search questions">
                <select name="subject" aria-label="Subject">
                    <option value="0">All subjects</option>
                    <?php foreach ($catalog as $s): ?>
                        <option value="<?php echo (int) $s['id']; ?>" <?php echo $filters['subject_id'] === (int) $s['id'] ? 'selected' : ''; ?>><?php echo e($s['name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="topic" aria-label="Topic">
                    <option value="0">All topics</option>
                    <?php foreach ($catalog as $s): foreach ($s['topics'] as $t): ?>
                        <option value="<?php echo (int) $t['id']; ?>" <?php echo $filters['topic_id'] === (int) $t['id'] ? 'selected' : ''; ?>><?php echo e($s['name'] . ': ' . $t['name']); ?></option>
                    <?php endforeach; endforeach; ?>
                </select>
                <select name="type" aria-label="Question type">
                    <option value="">All types</option>
                    <?php foreach (QUESTION_TYPES as $value => $label): ?>
                        <option value="<?php echo e($value); ?>" <?php echo $filters['type'] === $value ? 'selected' : ''; ?>><?php echo e($label); ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="difficulty" aria-label="Difficulty">
                    <option value="">Any difficulty</option>
                    <?php foreach (DIFFICULTIES as $value => $label): ?>
                        <option value="<?php echo e($value); ?>" <?php echo $filters['difficulty'] === $value ? 'selected' : ''; ?>><?php echo e($label); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-outline">Filter</button>
                <?php if ($list_query): ?><a class="btn btn-muted" href="question_bank.php">Clear</a><?php endif; ?>
            </form>

            <?php if (!$result['rows']): ?>
                <div class="empty-state">
                    <?php echo $list_query ? 'No questions match these filters.' : 'Your bank is empty. Add a question here, or use "Save to bank" on any quiz question.'; ?>
                </div>
            <?php else: ?>
                <form method="POST" id="bank-form"><?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="add_to_quiz">
                    <div class="filter-bar">
                        <select name="quiz_id" aria-label="Quiz to add the selected questions to" required>
                            <option value="">Add selected questions to…</option>
                            <?php foreach ($my_quizzes as $mq): ?>
                                <option value="<?php echo (int) $mq['id']; ?>"><?php echo e($mq['title']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn">Add to quiz</button>
                    </div>
                </form>

                <div class="card-list">
                    <?php foreach ($result['rows'] as $q): ?>
                        <div class="item-row is-stacked">
                            <div class="item-row-head">
                                <label class="form-check" style="align-items:flex-start">
                                    <input type="checkbox" name="ids[]" value="<?php echo (int) $q['id']; ?>" form="bank-form" aria-label="Select question <?php echo (int) $q['id']; ?>">
                                    <span class="item-title"><?php echo nl2br(e(mb_strimwidth($q['question_text'], 0, 220, '…'))); ?></span>
                                </label>
                                <div class="item-actions">
                                    <a class="btn btn-outline btn-sm" href="question_bank.php?<?php echo e($list_query ? $list_query . '&' : ''); ?>edit=<?php echo (int) $q['id']; ?>#question-editor">Edit</a>
                                    <form method="POST" data-confirm="Delete this question from your bank? Quizzes that use a copy are not affected." data-danger><?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="delete_question">
                                        <input type="hidden" name="question_id" value="<?php echo (int) $q['id']; ?>">
                                        <button type="submit" class="btn btn-danger-outline btn-sm">Delete</button>
                                    </form>
                                </div>
                            </div>
                            <div class="item-meta">
                                <span class="badge badge-info"><?php echo e(question_type_label($q['type'])); ?></span>
                                <span class="points-tag"><?php echo fmt_score($q['points']); ?> pt</span>
                                <?php if ($q['difficulty']): ?><span class="badge badge-draft"><?php echo e(DIFFICULTIES[$q['difficulty']] ?? ''); ?></span><?php endif; ?>
                                <?php if ($q['topic_name']): ?><span class="badge badge-draft"><?php echo e($q['subject_name'] . ': ' . $q['topic_name']); ?></span><?php endif; ?>
                                <?php if ((int) $q['used_in'] > 0): ?><span class="cell-muted">Used in <?php echo (int) $q['used_in']; ?> quiz question(s)</span><?php endif; ?>
                            </div>
                            <?php if (correct_answer_summary($q) !== ''): ?>
                                <p class="helptext" style="margin-bottom:0"><strong>Answer:</strong> <?php echo e(mb_strimwidth(correct_answer_summary($q), 0, 200, '…')); ?></p>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php if ($result['pages'] > 1): ?>
                    <nav class="table-pager" aria-label="Bank pages">
                        <span>Page <?php echo $result['page']; ?> of <?php echo $result['pages']; ?></span>
                        <?php for ($p = 1; $p <= $result['pages']; $p++): ?>
                            <?php if ($p === $result['page']): ?>
                                <button type="button" aria-current="page"><?php echo $p; ?></button>
                            <?php else: ?>
                                <a class="btn btn-outline btn-sm" href="question_bank.php?<?php echo e(($list_query ? $list_query . '&' : '') . 'page=' . $p); ?>"><?php echo $p; ?></a>
                            <?php endif; ?>
                        <?php endfor; ?>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <div class="card" id="catalog">
            <h2>Subjects &amp; topics</h2>
            <p class="helptext">Group questions by subject and topic. Topics drive the strongest/weakest topic analytics for students.</p>
            <div class="class-grid">
                <form method="POST" class="card" style="margin:0"><?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="create_subject">
                    <div class="form-field">
                        <label for="new-subject">New subject</label>
                        <div class="input-with-button">
                            <input type="text" id="new-subject" name="name" maxlength="<?php echo CATALOG_NAME_MAX; ?>" placeholder="e.g. Biology" required>
                            <button type="submit" class="btn">Add</button>
                        </div>
                    </div>
                </form>
                <?php if ($catalog): ?>
                    <form method="POST" class="card" style="margin:0"><?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="create_topic">
                        <div class="form-field">
                            <label for="topic-subject">Subject</label>
                            <select id="topic-subject" name="subject_id">
                                <?php foreach ($catalog as $s): ?><option value="<?php echo (int) $s['id']; ?>"><?php echo e($s['name']); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-field">
                            <label for="new-topic">New topic</label>
                            <div class="input-with-button">
                                <input type="text" id="new-topic" name="name" maxlength="<?php echo CATALOG_NAME_MAX; ?>" placeholder="e.g. Cell division" required>
                                <button type="submit" class="btn">Add</button>
                            </div>
                        </div>
                    </form>
                <?php endif; ?>
            </div>

            <?php if ($catalog): ?>
                <div class="table-wrap">
                    <table class="data-table" aria-label="Subjects and topics" data-enhance="off">
                        <thead><tr><th>Subject</th><th>Topic</th><th>Questions</th><th></th></tr></thead>
                        <tbody>
                            <?php foreach ($catalog as $s): ?>
                                <tr>
                                    <td><strong><?php echo e($s['name']); ?></strong></td>
                                    <td class="cell-muted"><?php echo count($s['topics']); ?> topic(s)</td>
                                    <td></td>
                                    <td>
                                        <form method="POST" data-confirm="Delete the subject <?php echo e($s['name']); ?> and its topics? Questions are kept, without a topic." data-danger><?php echo csrf_field(); ?>
                                            <input type="hidden" name="action" value="delete_subject">
                                            <input type="hidden" name="subject_id" value="<?php echo (int) $s['id']; ?>">
                                            <button type="submit" class="btn btn-danger-outline btn-sm">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                                <?php foreach ($s['topics'] as $t): ?>
                                    <tr>
                                        <td></td>
                                        <td><?php echo e($t['name']); ?></td>
                                        <td><?php echo (int) $t['question_count']; ?></td>
                                        <td>
                                            <form method="POST" data-confirm="Delete the topic <?php echo e($t['name']); ?>? Its questions are kept, without a topic." data-danger><?php echo csrf_field(); ?>
                                                <input type="hidden" name="action" value="delete_topic">
                                                <input type="hidden" name="topic_id" value="<?php echo (int) $t['id']; ?>">
                                                <button type="submit" class="btn btn-danger-outline btn-sm">Delete</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>

</html>
