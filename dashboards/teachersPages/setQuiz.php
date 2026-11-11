<?php
require_once __DIR__ . '/../../includes/bootstrap.php';

require_role('teacher', '../../auth/login.php');

// Each teacher manages only their own quizzes.
$teacher_email = current_user_email();
$success = "";
$error = "";

// --- Handle actions ------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_quiz') {
        $title = trim($_POST['title'] ?? '');
        if ($title === '' || mb_strlen($title) > 150) {
            $error = "Title is required (up to 150 characters).";
        } else {
            // New quizzes hold results back until the quiz closes, so students who
            // finish early cannot share the answers (the teacher can change this).
            $stmt = $conn->prepare("INSERT INTO quizzes (teacher_email, title, status, results_release) VALUES (?, ?, 'draft', 'after_close')");
            $stmt->bind_param("ss", $teacher_email, $title);
            $stmt->execute();
            $new_id = $stmt->insert_id;
            $stmt->close();
            header("Location: quiz_questions.php?quiz_id=" . (int) $new_id . "&new=1");
            exit;
        }
    } elseif ($action === 'update_settings') {
        $quiz_id = (int) ($_POST['quiz_id'] ?? 0);

        $own = $conn->prepare("SELECT id FROM quizzes WHERE id = ? AND teacher_email = ?");
        $own->bind_param("is", $quiz_id, $teacher_email);
        $own->execute();
        if ($own->get_result()->num_rows === 1) {
            $title = trim($_POST['title'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $time_limit = trim($_POST['time_limit_minutes'] ?? '');
            $time_limit = ($time_limit === '') ? null : max(1, (int) $time_limit);
            $shuffle_q = isset($_POST['shuffle_questions']) ? 1 : 0;
            $shuffle_o = isset($_POST['shuffle_options']) ? 1 : 0;
            $lock = isset($_POST['lock_on_leave']) ? 1 : 0;
            $release = (string) ($_POST['results_release'] ?? 'immediate');
            $release = isset(RESULTS_RELEASE[$release]) ? $release : 'immediate';
            $show_answers = isset($_POST['show_answers']) ? 1 : 0;
            $subject_id = (int) ($_POST['subject_id'] ?? 0);
            $subject_id = ($subject_id && teacher_owns_subject($conn, $teacher_email, $subject_id)) ? $subject_id : null;
            $mode = ($_POST['mode'] ?? '') === 'practice' ? 'practice' : 'official';
            $practice_enabled = isset($_POST['practice_enabled']) ? 1 : 0;
            $max_attempts_raw = trim($_POST['max_attempts'] ?? '1');
            $max_attempts = ($max_attempts_raw === '' || $max_attempts_raw === '0') ? 0 : max(1, (int) $max_attempts_raw);
            $start_time = trim($_POST['start_time'] ?? '');
            $start_time = $start_time === '' ? null : str_replace('T', ' ', $start_time) . ':00';
            $end_time = trim($_POST['end_time'] ?? '');
            $end_time = $end_time === '' ? null : str_replace('T', ' ', $end_time) . ':00';

            if ($title === '' || mb_strlen($title) > 150) {
                $error = "Title is required (up to 150 characters).";
            } elseif (mb_strlen($description) > 5000) {
                $error = "Description must be 5000 characters or fewer.";
            } elseif ($start_time && $end_time && strtotime($start_time) >= strtotime($end_time)) {
                $error = "End time must be after start time.";
            } else {
                $stmt = $conn->prepare(
                    "UPDATE quizzes SET title=?, description=?, time_limit_minutes=?, shuffle_questions=?, shuffle_options=?, lock_on_leave=?, max_attempts=?, start_time=?, end_time=?, results_release=?, show_answers=?, subject_id=?, mode=?, practice_enabled=? WHERE id=? AND teacher_email=?"
                );
                $stmt->bind_param(
                    "ssiiiiisssiisiis",
                    $title, $description, $time_limit, $shuffle_q, $shuffle_o, $lock, $max_attempts, $start_time, $end_time, $release, $show_answers, $subject_id, $mode, $practice_enabled, $quiz_id, $teacher_email
                );
                $stmt->execute();
                $stmt->close();
                // Sections that may take this quiz (none = every student).
                set_quiz_sections($conn, $teacher_email, $quiz_id, is_array($_POST['sections'] ?? null) ? $_POST['sections'] : []);
                $success = "Quiz settings saved.";
            }
        } else {
            $error = "Quiz not found.";
        }
        $own->close();
    } elseif ($action === 'set_status') {
        $quiz_id = (int) ($_POST['quiz_id'] ?? 0);
        $new_status = $_POST['status'] ?? '';
        if (in_array($new_status, ['draft', 'published', 'closed'], true)) {
            if ($new_status === 'published') {
                // Require at least one question before publishing.
                $qcount = $conn->prepare("SELECT COUNT(*) AS c FROM questions WHERE quiz_id = ? AND deleted_at IS NULL");
                $qcount->bind_param("i", $quiz_id);
                $qcount->execute();
                $has_questions = (int) ($qcount->get_result()->fetch_assoc()['c'] ?? 0) > 0;
                $qcount->close();
                if (!$has_questions) {
                    $error = "Add at least one question before publishing.";
                }
            }
            if (!$error) {
                $stmt = $conn->prepare("UPDATE quizzes SET status = ? WHERE id = ? AND teacher_email = ?");
                $stmt->bind_param("sis", $new_status, $quiz_id, $teacher_email);
                $stmt->execute();
                $changed = $stmt->affected_rows === 1;
                $stmt->close();
                $success = "Quiz status updated.";
                if ($changed && $new_status === 'published') {
                    $sent = notify_quiz_assigned($conn, $teacher_email, $quiz_id);
                    $success .= $sent ? " $sent student(s) notified." : '';
                } elseif ($changed && $new_status === 'closed') {
                    notify_results_released($conn, $quiz_id);
                }
            }
        }
    } elseif ($action === 'duplicate_quiz') {
        // Copy a quiz with its questions and options as a new draft.
        $quiz_id = (int) ($_POST['quiz_id'] ?? 0);
        $own = $conn->prepare("SELECT id FROM quizzes WHERE id = ? AND teacher_email = ?");
        $own->bind_param("is", $quiz_id, $teacher_email);
        $own->execute();
        $owned = $own->get_result()->num_rows === 1;
        $own->close();

        if (!$owned) {
            $error = "Quiz not found.";
        } else {
            try {
                $conn->begin_transaction();

                $stmt = $conn->prepare(
                    "INSERT INTO quizzes (teacher_email, title, description, time_limit_minutes, shuffle_questions, shuffle_options, lock_on_leave, max_attempts, results_release, show_answers, subject_id, mode, practice_enabled, status)
                     SELECT ?, LEFT(CONCAT(title, ' (copy)'), 150), description, time_limit_minutes,
                            shuffle_questions, shuffle_options, lock_on_leave, max_attempts, results_release, show_answers, subject_id, mode, practice_enabled, 'draft'
                     FROM quizzes WHERE id = ?"
                );
                $stmt->bind_param("si", $teacher_email, $quiz_id);
                $stmt->execute();
                $new_quiz_id = (int) $stmt->insert_id;
                $stmt->close();

                $qs = $conn->prepare("SELECT id, type, question_text, points, order_index, model_answer, difficulty, topic_id, explanation, answer_config FROM questions WHERE quiz_id = ? AND deleted_at IS NULL ORDER BY order_index ASC");
                $qs->bind_param("i", $quiz_id);
                $qs->execute();
                $old_questions = $qs->get_result()->fetch_all(MYSQLI_ASSOC);
                $qs->close();

                $insq = $conn->prepare(
                    "INSERT INTO questions (quiz_id, teacher_email, type, question_text, points, order_index, model_answer, difficulty, topic_id, explanation, answer_config, source_question_id)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                );
                $opts = $conn->prepare("SELECT option_text, is_correct, order_index FROM question_options WHERE question_id = ? ORDER BY order_index, id");
                $inso = $conn->prepare("INSERT INTO question_options (question_id, option_text, is_correct, order_index) VALUES (?, ?, ?, ?)");

                foreach ($old_questions as $q) {
                    $old_qid = (int) $q['id'];
                    $insq->bind_param("isssdississi", $new_quiz_id, $teacher_email, $q['type'], $q['question_text'], $q['points'], $q['order_index'],
                        $q['model_answer'], $q['difficulty'], $q['topic_id'], $q['explanation'], $q['answer_config'], $old_qid);
                    $insq->execute();
                    $new_qid = (int) $insq->insert_id;

                    $opts->bind_param("i", $q['id']);
                    $opts->execute();
                    foreach ($opts->get_result()->fetch_all(MYSQLI_ASSOC) as $o) {
                        $inso->bind_param("isii", $new_qid, $o['option_text'], $o['is_correct'], $o['order_index']);
                        $inso->execute();
                    }
                }
                $insq->close();
                $opts->close();
                $inso->close();

                $secs = $conn->prepare(
                    "INSERT INTO quiz_assignments (quiz_id, target_type, section_id, student_email, available_from, due_at, late_until)
                     SELECT ?, target_type, section_id, student_email, available_from, due_at, late_until FROM quiz_assignments WHERE quiz_id = ?"
                );
                $secs->bind_param("ii", $new_quiz_id, $quiz_id);
                $secs->execute();
                $secs->close();

                $conn->commit();
                $success = "Quiz duplicated as a draft.";
            } catch (Throwable $e) {
                $conn->rollback();
                error_log("Duplicate quiz failed: " . $e->getMessage());
                $error = "Could not duplicate the quiz.";
            }
        }
    } elseif ($action === 'release_results') {
        // Show (or hide again) scores and feedback to students right now,
        // whatever the quiz's release setting.
        $quiz_id = (int) ($_POST['quiz_id'] ?? 0);
        $release_now = ($_POST['release'] ?? '') === '1';
        $stmt = $conn->prepare($release_now
            ? "UPDATE quizzes SET results_released_at = NOW() WHERE id = ? AND teacher_email = ?"
            : "UPDATE quizzes SET results_released_at = NULL WHERE id = ? AND teacher_email = ?");
        $stmt->bind_param("is", $quiz_id, $teacher_email);
        $stmt->execute();
        $changed = $stmt->affected_rows === 1;
        $stmt->close();
        if ($changed) {
            $success = $release_now ? "Results released to students." : "Results hidden from students again.";
            if ($release_now) {
                notify_results_released($conn, $quiz_id);
            }
        }
    } elseif ($action === 'delete_quiz') {
        $quiz_id = (int) ($_POST['quiz_id'] ?? 0);
        $stmt = $conn->prepare("DELETE FROM quizzes WHERE id = ? AND teacher_email = ?");
        $stmt->bind_param("is", $quiz_id, $teacher_email);
        $stmt->execute();
        $stmt->close();
        $success = "Quiz deleted.";
    }
}

// --- Load quizzes for listing -------------------------------------------
$stmt = $conn->prepare(
    "SELECT q.*,
        (SELECT COUNT(*) FROM questions qq WHERE qq.quiz_id = q.id AND qq.deleted_at IS NULL) AS question_count,
        (SELECT COUNT(*) FROM quiz_attempts qa WHERE qa.quiz_id = q.id AND qa.status='submitted' AND qa.is_practice = 0) AS attempt_count
     FROM quizzes q WHERE q.teacher_email = ? ORDER BY q.created_at DESC"
);
$stmt->bind_param("s", $teacher_email);
$stmt->execute();
$quizzes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$my_sections = teacher_sections($conn, $teacher_email);
$catalog = teacher_catalog($conn, $teacher_email);
foreach ($quizzes as &$quiz_row) {
    $quiz_row['section_ids'] = quiz_section_ids($conn, (int) $quiz_row['id']);
}
unset($quiz_row);

$active = 'quizzes';
$nav_base = '../';
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../navStyle.css?v=9">
    <link rel="stylesheet" href="../appStyle.css?v=9">
    <title>Manage Quizzes</title>
</head>

<body>
    <?php include APP_ROOT . '/dashboards/partials/nav_teacher.php'; ?>

    <div class="container" id="main">
        <div class="page-header">
            <div>
                <h1>Manage Quizzes</h1>
                <p class="subtitle">Create quizzes, add questions, and control availability.</p>
            </div>
        </div>

        <?php if ($success): ?><div class="alert alert-success"><?php echo e($success); ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-error"><?php echo e($error); ?></div><?php endif; ?>

        <div class="card">
            <h2>Create a New Quiz</h2>
            <form method="POST"><?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="create_quiz">
                <div class="form-grid">
                    <div class="form-field">
                        <label for="title">Quiz Title</label>
                        <input type="text" id="title" name="title" placeholder="e.g. Chapter 3 Review" required>
                    </div>
                </div>
                <button type="submit" class="btn">Create &amp; Add Questions</button>
                <p class="helptext">You'll add questions next. New quizzes start as drafts (hidden from students) until you publish them.</p>
            </form>
        </div>

        <div class="card">
            <h2>My Quizzes</h2>
            <?php if (empty($quizzes)): ?>
                <div class="empty-state">No quizzes yet. Create one above.</div>
            <?php else: ?>
                <div class="card-list">
                    <?php foreach ($quizzes as $q): $avail = quiz_availability($q); ?>
                        <div class="item-row is-stacked">
                            <div class="item-row-head">
                                <div>
                                    <div class="item-title"><?php echo e($q['title']); ?></div>
                                    <div class="item-meta">
                                        <?php echo (int) $q['question_count']; ?> question(s)
                                        &middot; <?php echo (int) $q['attempt_count']; ?> submitted attempt(s)
                                        &middot; <?php echo $q['time_limit_minutes'] ? (int) $q['time_limit_minutes'] . ' min limit' : 'No time limit'; ?>
                                        &middot; <?php echo attempts_label((int) $q['max_attempts']); ?> attempt(s) allowed
                                        &middot; <?php echo $q['section_ids'] ? count($q['section_ids']) . ' section(s)' : 'All my students'; ?>
                                        &middot; <?php echo results_released($q) ? 'Results visible to students' : 'Results hidden from students'; ?>
                                    </div>
                                </div>
                                <div class="item-actions">
                                    <span class="badge badge-<?php echo e($q['status']); ?>"><?php echo ucfirst(e($q['status'])); ?></span>
                                    <?php if (($q['mode'] ?? '') === 'practice'): ?><span class="badge badge-info">Practice only</span><?php elseif ((int) ($q['practice_enabled'] ?? 0) === 1): ?><span class="badge badge-info">Practice allowed</span><?php endif; ?>
                                    <span class="badge <?php echo $avail['open'] ? 'badge-open' : 'badge-closed'; ?>"><?php echo e($avail['label']); ?></span>
                                </div>
                            </div>

                            <div class="item-actions is-spaced">
                                <a class="btn btn-outline btn-sm" href="quiz_questions.php?quiz_id=<?php echo (int) $q['id']; ?>">Questions</a>
                                <a class="btn btn-outline btn-sm" href="assignments.php?quiz_id=<?php echo (int) $q['id']; ?>">Assign</a>
                                <a class="btn btn-outline btn-sm" href="results.php?quiz_id=<?php echo (int) $q['id']; ?>">Results</a>

                                <?php if ($q['status'] !== 'published'): ?>
                                    <form method="POST" style="display:inline;"><?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="set_status">
                                        <input type="hidden" name="quiz_id" value="<?php echo (int) $q['id']; ?>">
                                        <input type="hidden" name="status" value="published">
                                        <button type="submit" class="btn btn-sm">Publish</button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($q['status'] === 'published'): ?>
                                    <form method="POST" style="display:inline;"><?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="set_status">
                                        <input type="hidden" name="quiz_id" value="<?php echo (int) $q['id']; ?>">
                                        <input type="hidden" name="status" value="closed">
                                        <button type="submit" class="btn btn-outline btn-sm">Close</button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($q['status'] === 'closed'): ?>
                                    <form method="POST" style="display:inline;"><?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="set_status">
                                        <input type="hidden" name="quiz_id" value="<?php echo (int) $q['id']; ?>">
                                        <input type="hidden" name="status" value="draft">
                                        <button type="submit" class="btn btn-outline btn-sm">Reopen as Draft</button>
                                    </form>
                                <?php endif; ?>

                                <button type="button" class="btn btn-outline btn-sm" data-toggle="#settings-<?php echo (int) $q['id']; ?>">Settings</button>

                                <?php if ($q['results_release'] !== 'immediate'): ?>
                                    <form method="POST" style="display:inline;"><?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="release_results">
                                        <input type="hidden" name="quiz_id" value="<?php echo (int) $q['id']; ?>">
                                        <?php if (empty($q['results_released_at'])): ?>
                                            <input type="hidden" name="release" value="1">
                                            <button type="submit" class="btn btn-outline btn-sm">Release results now</button>
                                        <?php else: ?>
                                            <input type="hidden" name="release" value="0">
                                            <button type="submit" class="btn btn-outline btn-sm">Hide results</button>
                                        <?php endif; ?>
                                    </form>
                                <?php endif; ?>

                                <form method="POST" style="display:inline;"><?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="duplicate_quiz">
                                    <input type="hidden" name="quiz_id" value="<?php echo (int) $q['id']; ?>">
                                    <button type="submit" class="btn btn-outline btn-sm">Duplicate</button>
                                </form>

                                <form method="POST" data-confirm="Delete this quiz and all its attempts? This cannot be undone." data-danger style="display:inline;"><?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="delete_quiz">
                                    <input type="hidden" name="quiz_id" value="<?php echo (int) $q['id']; ?>">
                                    <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                                </form>
                            </div>

                            <div id="settings-<?php echo (int) $q['id']; ?>" class="hidden-panel settings-panel">
                                <form method="POST"><?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="update_settings">
                                    <input type="hidden" name="quiz_id" value="<?php echo (int) $q['id']; ?>">
                                    <div class="form-grid">
                                        <div class="form-field">
                                            <label for="title-<?php echo (int) $q['id']; ?>">Title</label>
                                            <input type="text" id="title-<?php echo (int) $q['id']; ?>" name="title" value="<?php echo e($q['title']); ?>" required>
                                        </div>
                                        <div class="form-field">
                                            <label for="limit-<?php echo (int) $q['id']; ?>">Time Limit (minutes, blank = none)</label>
                                            <input type="number" min="1" id="limit-<?php echo (int) $q['id']; ?>" name="time_limit_minutes" value="<?php echo e($q['time_limit_minutes']); ?>">
                                        </div>
                                        <div class="form-field">
                                            <label for="attempts-<?php echo (int) $q['id']; ?>">Max Attempts (0 = unlimited)</label>
                                            <input type="number" min="0" id="attempts-<?php echo (int) $q['id']; ?>" name="max_attempts" value="<?php echo (int) $q['max_attempts']; ?>">
                                        </div>
                                        <div class="form-field">
                                            <label for="opens-<?php echo (int) $q['id']; ?>">Opens At (optional)</label>
                                            <input type="datetime-local" id="opens-<?php echo (int) $q['id']; ?>" name="start_time" value="<?php echo $q['start_time'] ? date('Y-m-d\TH:i', strtotime($q['start_time'])) : ''; ?>">
                                        </div>
                                        <div class="form-field">
                                            <label for="closes-<?php echo (int) $q['id']; ?>">Closes At (optional)</label>
                                            <input type="datetime-local" id="closes-<?php echo (int) $q['id']; ?>" name="end_time" value="<?php echo $q['end_time'] ? date('Y-m-d\TH:i', strtotime($q['end_time'])) : ''; ?>">
                                        </div>
                                    </div>
                                    <div class="form-field" style="margin-bottom:10px;">
                                        <label for="desc-<?php echo (int) $q['id']; ?>">Description</label>
                                        <textarea id="desc-<?php echo (int) $q['id']; ?>" name="description" rows="2"><?php echo e($q['description']); ?></textarea>
                                    </div>
                                    <div class="check-row">
                                        <label class="form-check"><input type="checkbox" name="shuffle_questions" <?php echo $q['shuffle_questions'] ? 'checked' : ''; ?>> Shuffle question order</label>
                                        <label class="form-check"><input type="checkbox" name="shuffle_options" <?php echo $q['shuffle_options'] ? 'checked' : ''; ?>> Shuffle answer options</label>
                                        <label class="form-check"><input type="checkbox" name="lock_on_leave" <?php echo (int) ($q['lock_on_leave'] ?? 1) === 1 ? 'checked' : ''; ?>> Exam mode (full screen; auto-submit if the student leaves)</label>
                                    </div>
                                    <div class="form-grid">
                                        <div class="form-field">
                                            <label for="mode-<?php echo (int) $q['id']; ?>">Mode</label>
                                            <select id="mode-<?php echo (int) $q['id']; ?>" name="mode">
                                                <option value="official" <?php echo ($q['mode'] ?? 'official') === 'official' ? 'selected' : ''; ?>>Official (graded)</option>
                                                <option value="practice" <?php echo ($q['mode'] ?? '') === 'practice' ? 'selected' : ''; ?>>Practice only (unlimited, never graded)</option>
                                            </select>
                                        </div>
                                        <div class="form-field">
                                            <label for="subject-<?php echo (int) $q['id']; ?>">Subject</label>
                                            <select id="subject-<?php echo (int) $q['id']; ?>" name="subject_id">
                                                <option value="0">No subject</option>
                                                <?php foreach ($catalog as $subj): ?>
                                                    <option value="<?php echo (int) $subj['id']; ?>" <?php echo (int) ($q['subject_id'] ?? 0) === (int) $subj['id'] ? 'selected' : ''; ?>><?php echo e($subj['name']); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="form-field">
                                            <label for="release-<?php echo (int) $q['id']; ?>">Students see their score and feedback</label>
                                            <select id="release-<?php echo (int) $q['id']; ?>" name="results_release">
                                                <?php foreach (RESULTS_RELEASE as $value => $label): ?>
                                                    <option value="<?php echo e($value); ?>" <?php echo $q['results_release'] === $value ? 'selected' : ''; ?>><?php echo e($label); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="check-row">
                                        <label class="form-check"><input type="checkbox" name="show_answers" <?php echo (int) $q['show_answers'] === 1 ? 'checked' : ''; ?>> Show the correct answers once results are visible</label>
                                        <label class="form-check"><input type="checkbox" name="practice_enabled" <?php echo (int) ($q['practice_enabled'] ?? 0) === 1 ? 'checked' : ''; ?>> Allow practice after the results and answers are released</label>
                                    </div>
                                    <fieldset class="section-picker">
                                        <legend>Who can take it</legend>
                                        <?php if (!$my_sections): ?>
                                            <p class="helptext">Only students in your sections can take your quizzes. Create sections and add students under <a class="link" href="classes.php">Classes</a>.</p>
                                        <?php else: ?>
                                            <p class="helptext">Tick the sections that should take this quiz. Leave all unticked to open it to every student in your sections. For deadlines, late submissions or individual students, use <a class="link" href="assignments.php?quiz_id=<?php echo (int) $q['id']; ?>">Assignments</a>.</p>
                                            <div class="check-row">
                                                <?php foreach ($my_sections as $s): ?>
                                                    <label class="form-check"><input type="checkbox" name="sections[]" value="<?php echo (int) $s['id']; ?>" <?php echo in_array((int) $s['id'], $q['section_ids'], true) ? 'checked' : ''; ?>> <?php echo e($s['name']); ?></label>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </fieldset>
                                    <button type="submit" class="btn btn-sm">Save Settings</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <style>.hidden-panel { display: none; }</style>
</body>

</html>
