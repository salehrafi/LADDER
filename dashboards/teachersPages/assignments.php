<?php
/**
 * Assign one quiz (to all students, sections or selected students, with
 * deadlines) and track completion.
 */
require_once __DIR__ . '/../../includes/bootstrap.php';

require_role('teacher', '../../auth/login.php');

$teacher_email = current_user_email();
$quiz_id = (int) ($_GET['quiz_id'] ?? $_POST['quiz_id'] ?? 0);

$stmt = $conn->prepare("SELECT * FROM quizzes WHERE id = ? AND teacher_email = ?");
$stmt->bind_param("is", $quiz_id, $teacher_email);
$stmt->execute();
$quiz = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$quiz) {
    redirect('setQuiz.php');
}
$page = "assignments.php?quiz_id=$quiz_id";
$error = '';
$form = ['target_type' => 'all', 'available_from' => '', 'due_at' => '', 'late_until' => '', 'targets' => []];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'assign') {
        $form = [
            'target_type' => (string) ($_POST['target_type'] ?? 'all'),
            'available_from' => (string) ($_POST['available_from'] ?? ''),
            'due_at' => (string) ($_POST['due_at'] ?? ''),
            'late_until' => (string) ($_POST['late_until'] ?? ''),
            'targets' => is_array($_POST['targets'] ?? null) ? $_POST['targets'] : [],
        ];
        $targets = $form['target_type'] === 'section'
            ? (is_array($_POST['sections'] ?? null) ? $_POST['sections'] : [])
            : (is_array($_POST['students'] ?? null) ? $_POST['students'] : []);
        try {
            $n = assign_quiz($conn, $teacher_email, $quiz_id, $form['target_type'], $targets,
                form_datetime($form['available_from']), form_datetime($form['due_at']), form_datetime($form['late_until']));
            $sent = notify_quiz_assigned($conn, $teacher_email, $quiz_id);
            flash('success', "Assignment saved ($n target" . ($n === 1 ? '' : 's') . ')' . ($sent ? "; $sent student(s) notified." : '.')
                . ($quiz['status'] !== 'published' ? ' Students are notified when you publish the quiz.' : ''));
            redirect($page);
        } catch (InvalidArgumentException $e) {
            $error = $e->getMessage();
        }
    } elseif ($action === 'remove') {
        if (remove_assignment($conn, $teacher_email, (int) ($_POST['assignment_id'] ?? 0))) {
            flash('success', 'Assignment removed.');
        }
        redirect($page);
    }
}

$assignments = quiz_assignment_list($conn, $quiz_id);
$sections = teacher_sections($conn, $teacher_email);
$roster = assignment_roster($conn, $teacher_email, $quiz);
$counts = array_fill_keys(array_keys(STATUS_LABELS), 0);
foreach ($roster as $r) {
    $counts[$r['status']]++;
}
$done = $counts['completed'] + $counts['completed_late'];

// Students the teacher can pick individually (everyone in their sections).
$stmt = $conn->prepare(
    "SELECT DISTINCT r.email, r.full_name FROM section_students ss
     JOIN sections s ON s.id = ss.section_id JOIN reg_info r ON r.email = ss.student_email
     WHERE s.teacher_email = ? ORDER BY r.full_name"
);
$stmt->bind_param("s", $teacher_email);
$stmt->execute();
$my_students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$active = 'quizzes';
$nav_base = '../';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../navStyle.css?v=9">
    <link rel="stylesheet" href="../appStyle.css?v=10">
    <title>Assign · <?php echo e($quiz['title']); ?></title>
</head>

<body>
    <?php include APP_ROOT . '/dashboards/partials/nav_teacher.php'; ?>

    <div class="container" id="main">
        <div class="page-header">
            <div>
                <h1>Assign: <?php echo e($quiz['title']); ?></h1>
                <p class="subtitle">
                    <span class="badge badge-<?php echo e($quiz['status']); ?>"><?php echo e(ucfirst($quiz['status'])); ?></span>
                    Choose who takes this quiz and when, and follow who has completed it.
                </p>
            </div>
            <div class="item-actions">
                <a class="btn btn-outline" href="results.php?quiz_id=<?php echo $quiz_id; ?>">Results</a>
                <a class="btn btn-outline" href="setQuiz.php">&larr; Quizzes</a>
            </div>
        </div>

        <?php if ($quiz['status'] !== 'published'): ?>
            <div class="alert alert-info">This quiz is a <?php echo e($quiz['status']); ?>. Students see it once it is published, within the dates below.</div>
        <?php endif; ?>
        <?php if ($error): ?><div class="alert alert-error" role="alert"><?php echo e($error); ?></div><?php endif; ?>

        <div class="stat-grid">
            <div class="stat-card is-navy"><span class="stat-icon"><?php echo icon('users'); ?></span><div class="stat-value"><?php echo count($roster); ?></div><div class="stat-label">Assigned students</div></div>
            <div class="stat-card is-success"><span class="stat-icon"><?php echo icon('check'); ?></span><div class="stat-value"><?php echo $done; ?></div><div class="stat-label">Completed<?php echo $counts['completed_late'] ? ' (' . $counts['completed_late'] . ' late)' : ''; ?></div></div>
            <div class="stat-card"><span class="stat-icon"><?php echo icon('clock'); ?></span><div class="stat-value"><?php echo $counts['not_started'] + $counts['in_progress'] + $counts['upcoming']; ?></div><div class="stat-label">Not finished yet</div></div>
            <div class="stat-card <?php echo $counts['overdue'] ? 'is-warning' : ''; ?>"><span class="stat-icon"><?php echo icon('alert'); ?></span><div class="stat-value"><?php echo $counts['overdue']; ?></div><div class="stat-label">Overdue</div></div>
        </div>

        <div class="card">
            <h2>Current assignments</h2>
            <?php if (!$assignments): ?>
                <div class="empty-state">No assignments yet: the quiz is open to all of your students, with the quiz's own dates
                    (<?php echo $quiz['start_time'] ? 'opens ' . e(date('M j, Y g:i A', strtotime($quiz['start_time']))) : 'no opening date'; ?>,
                    <?php echo $quiz['end_time'] ? 'closes ' . e(date('M j, Y g:i A', strtotime($quiz['end_time']))) : 'no deadline'; ?>).</div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="data-table" aria-label="Assignments" data-enhance="off">
                        <thead><tr><th>Assigned to</th><th>Opens</th><th>Due</th><th>Late until</th><th></th></tr></thead>
                        <tbody>
                            <?php foreach ($assignments as $a): ?>
                                <tr>
                                    <td>
                                        <?php if ($a['target_type'] === 'all'): ?><strong>All my students</strong>
                                        <?php elseif ($a['target_type'] === 'section'): ?>Section: <strong><?php echo e($a['section_name']); ?></strong>
                                        <?php else: ?>Student: <strong><?php echo e($a['student_name'] ?? $a['student_email']); ?></strong><?php endif; ?>
                                    </td>
                                    <td><?php echo $a['available_from'] ? e(date('M j, Y g:i A', strtotime($a['available_from']))) : '<span class="cell-muted">Quiz default</span>'; ?></td>
                                    <td><?php echo $a['due_at'] ? e(date('M j, Y g:i A', strtotime($a['due_at']))) : '<span class="cell-muted">Quiz default</span>'; ?></td>
                                    <td><?php echo $a['late_until'] ? e(date('M j, Y g:i A', strtotime($a['late_until']))) : '<span class="cell-muted">No late submissions</span>'; ?></td>
                                    <td>
                                        <form method="POST" data-confirm="Remove this assignment? Results already submitted are kept." data-danger><?php echo csrf_field(); ?>
                                            <input type="hidden" name="action" value="remove">
                                            <input type="hidden" name="quiz_id" value="<?php echo $quiz_id; ?>">
                                            <input type="hidden" name="assignment_id" value="<?php echo (int) $a['id']; ?>">
                                            <button type="submit" class="btn btn-danger-outline btn-sm">Remove</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p class="helptext">When a student matches several assignments, the most specific one applies (student, then section, then all students).</p>
            <?php endif; ?>
        </div>

        <div class="card">
            <h2>Assign this quiz</h2>
            <form method="POST" id="assign-form"><?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="assign">
                <input type="hidden" name="quiz_id" value="<?php echo $quiz_id; ?>">
                <fieldset class="section-picker">
                    <legend>Who</legend>
                    <div class="check-row">
                        <?php foreach (ASSIGNMENT_TARGETS as $value => $label): ?>
                            <label class="form-check"><input type="radio" name="target_type" value="<?php echo e($value); ?>" data-target-type <?php echo $form['target_type'] === $value ? 'checked' : ''; ?>> <?php echo e($label); ?></label>
                        <?php endforeach; ?>
                    </div>
                    <div data-target-panel="section">
                        <?php if (!$sections): ?>
                            <p class="helptext">You have no sections yet. Create them under <a class="link" href="classes.php">Classes</a>.</p>
                        <?php else: ?>
                            <div class="check-row">
                                <?php foreach ($sections as $s): ?>
                                    <label class="form-check"><input type="checkbox" name="sections[]" value="<?php echo (int) $s['id']; ?>"> <?php echo e($s['name']); ?> <span class="cell-muted">(<?php echo (int) $s['student_count']; ?>)</span></label>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div data-target-panel="student">
                        <?php if (!$my_students): ?>
                            <p class="helptext">No students in your sections yet.</p>
                        <?php else: ?>
                            <div class="check-row" style="max-height:220px;overflow:auto">
                                <?php foreach ($my_students as $st): ?>
                                    <label class="form-check"><input type="checkbox" name="students[]" value="<?php echo e($st['email']); ?>"> <?php echo e($st['full_name']); ?></label>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </fieldset>
                <div class="form-grid">
                    <div class="form-field">
                        <label for="a-from">Opens (optional)</label>
                        <input type="datetime-local" id="a-from" name="available_from" value="<?php echo e($form['available_from']); ?>">
                    </div>
                    <div class="form-field">
                        <label for="a-due">Due (optional)</label>
                        <input type="datetime-local" id="a-due" name="due_at" value="<?php echo e($form['due_at']); ?>">
                    </div>
                    <div class="form-field">
                        <label for="a-late">Accept late submissions until (optional)</label>
                        <input type="datetime-local" id="a-late" name="late_until" value="<?php echo e($form['late_until']); ?>">
                    </div>
                </div>
                <p class="helptext">Leave dates empty to use the quiz's own opening and closing times. Assigning the same target again updates its dates.</p>
                <button type="submit" class="btn">Save assignment</button>
            </form>
        </div>

        <div class="card">
            <h2>Completion</h2>
            <?php if (!$roster): ?>
                <div class="empty-state">No students yet. Add students to your sections under <a class="link" href="classes.php">Classes</a>.</div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="data-table" aria-label="Completion">
                        <thead><tr><th>Student</th><th>Section</th><th>Status</th><th>Due</th><th>Attempts</th><th>Best</th><th>Last submitted</th></tr></thead>
                        <tbody>
                            <?php foreach ($roster as $r): $best = $r['summary']['best_pct']; ?>
                                <tr>
                                    <td><?php echo e($r['name']); ?><div class="cell-muted"><?php echo e($r['email']); ?></div></td>
                                    <td class="cell-muted"><?php echo e($r['sections'] ?: '-'); ?></td>
                                    <td data-sort="<?php echo e($r['status']); ?>"><span class="badge <?php echo STATUS_BADGES[$r['status']]; ?>"><?php echo e(STATUS_LABELS[$r['status']]); ?></span></td>
                                    <td data-sort="<?php echo e($r['window']['due_at'] ?? ''); ?>"><?php echo !empty($r['window']['due_at']) ? e(date('M j, g:i A', strtotime($r['window']['due_at']))) : '-'; ?></td>
                                    <td><?php echo (int) $r['summary']['submitted']; ?></td>
                                    <td data-sort="<?php echo $best === null ? -1 : $best; ?>"><?php echo $best === null ? '-' : '<span class="score-pill ' . score_band($best * 100) . '">' . round($best * 100) . '%</span>'; ?></td>
                                    <td class="cell-muted"><?php echo $r['summary']['last_submitted'] ? e(date('M j, Y g:i A', strtotime($r['summary']['last_submitted']))) : '-'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Show only the picker for the chosen target type.
        (function () {
            const radios = document.querySelectorAll('[data-target-type]');
            function apply() {
                const type = document.querySelector('[data-target-type]:checked')?.value;
                document.querySelectorAll('[data-target-panel]').forEach(p => { p.hidden = p.dataset.targetPanel !== type; });
            }
            radios.forEach(r => r.addEventListener('change', apply));
            apply();
        })();
    </script>
</body>

</html>
