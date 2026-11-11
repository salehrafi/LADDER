<?php
require_once __DIR__ . '/../../includes/bootstrap.php';

require_role('teacher', '../../auth/login.php');

// The teacher's own students (in their sections, or who took their quizzes).
// The teacher is the administrator of these students: edit, reset, remove.
// Results shown are only for this teacher's quizzes.
$teacher_email = current_user_email();
$success = "";
$error = "";
$shared_password = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $target = trim((string) ($_POST['student_email'] ?? ''));

    if ($target === '' || !teacher_has_student($conn, $teacher_email, $target)) {
        $error = "That student is not in your classes.";
    } elseif ($action === 'update_student') {
        try {
            // Account details only for accounts this teacher created; anyone's
            // sections in this teacher's classes can be changed.
            $new_email = $target;
            if (teacher_manages_account($conn, $teacher_email, $target)) {
                $new_email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
                update_student($conn, $teacher_email, $target, (string) ($_POST['full_name'] ?? ''), $new_email, (string) ($_POST['student_id'] ?? ''));
            }
            set_student_sections($conn, $teacher_email, $new_email, is_array($_POST['sections'] ?? null) ? $_POST['sections'] : []);
            header('Location: student_profile.php?email=' . urlencode($new_email) . '&saved=1');
            exit;
        } catch (InvalidArgumentException $e) {
            $error = $e->getMessage();
        }
    } elseif ($action === 'reset_password') {
        $password = trim((string) ($_POST['default_password'] ?? '')) ?: generate_default_password();
        if ($rule = password_rule_error($password)) {
            $error = "Default password: $rule";
        } elseif (!reset_student_password($conn, $teacher_email, $target, $password)) {
            $error = "Only the teacher who created this account can reset its password. The student can use \"Forgot password\" on the sign-in page.";
        } else {
            $success = "Default password set. The student must choose a new password when they log in.";
            $shared_password = $password;
        }
    } elseif ($action === 'delete_student') {
        $result = remove_student($conn, $teacher_email, $target);
        header('Location: student_profile.php?deleted=' . ($result === 'deleted' ? 'account' : 'mine'));
        exit;
    }
}
if (isset($_GET['saved'])) {
    $success = $success ?: "Student details saved.";
}
if (isset($_GET['deleted'])) {
    $success = $success ?: ($_GET['deleted'] === 'account'
        ? "Student deleted, with their results on your quizzes."
        : "Student removed from your classes and their results on your quizzes deleted. Their account is kept.");
}

// My students: in one of my sections, or submitted one of my quizzes.
// Performance counts my quizzes only.
$list_sql =
    "SELECT r.id, r.full_name, r.email,
        COUNT(qa.id) AS attempts_taken,
        COUNT(DISTINCT qa.quiz_id) AS quizzes_taken,
        AVG(qa.total_score / NULLIF(qa.max_score, 0)) AS avg_pct,
        MAX(qa.submitted_at) AS last_submitted,
        SUM(CASE WHEN qa.needs_manual_grading = 1 THEN 1 ELSE 0 END) AS pending_count
     FROM reg_info r
     LEFT JOIN quiz_attempts qa
        ON qa.student_email = r.email AND qa.status = 'submitted' AND qa.is_practice = 0
        AND qa.quiz_id IN (SELECT id FROM quizzes WHERE teacher_email = ?)
     WHERE r.role = 'student' AND (
        EXISTS (SELECT 1 FROM section_students ss JOIN sections s ON s.id = ss.section_id
                WHERE ss.student_email = r.email AND s.teacher_email = ?)
        OR EXISTS (SELECT 1 FROM quiz_attempts qa2 JOIN quizzes q2 ON q2.id = qa2.quiz_id
                   WHERE qa2.student_email = r.email AND q2.teacher_email = ?))
     GROUP BY r.id, r.full_name, r.email
     ORDER BY r.full_name ASC";

$stmt = $conn->prepare($list_sql);
$stmt->bind_param("sss", $teacher_email, $teacher_email, $teacher_email);
$stmt->execute();
$students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$sections_by_student = section_names_by_student($conn, $teacher_email);
foreach ($students as &$st) {
    $st['sections'] = $sections_by_student[$st['email']] ?? '';
}
unset($st);

// --- CSV export of the roster ------------------------------------------------
if (isset($_GET['export'])) {
    send_csv('students.csv',
        ['Name', 'Email', 'Student ID', 'Sections', 'Quizzes taken', 'Attempts', 'Average %', 'Last submitted', 'Pending answers'],
        array_map(fn($s) => [
            $s['full_name'],
            $s['email'],
            $s['id'] ?? '',
            $s['sections'],
            (int) $s['quizzes_taken'],
            (int) $s['attempts_taken'],
            $s['avg_pct'] !== null ? round($s['avg_pct'] * 100, 1) : '',
            $s['last_submitted'] ?? '',
            (int) $s['pending_count'],
        ], $students));
}

// --- Single student detail ---------------------------------------------------
$detail_email = trim($_GET['email'] ?? '');
$detail = null;
$detail_attempts = [];

if ($detail_email !== '' && teacher_has_student($conn, $teacher_email, $detail_email)) {
    $stmt = $conn->prepare("SELECT id, full_name, email, must_change_password FROM reg_info WHERE email = ? AND role = 'student' LIMIT 1");
    $stmt->bind_param("s", $detail_email);
    $stmt->execute();
    $detail = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($detail) {
        $stmt = $conn->prepare(
            "SELECT qa.id, qa.attempt_number, qa.submitted_at, qa.due_at, qa.total_score, qa.max_score,
                    qa.needs_manual_grading, q.id AS quiz_id, q.title AS quiz_title
             FROM quiz_attempts qa
             JOIN quizzes q ON q.id = qa.quiz_id
             WHERE q.teacher_email = ? AND qa.student_email = ? AND qa.status = 'submitted' AND qa.is_practice = 0
             ORDER BY q.title ASC, qa.attempt_number ASC"
        );
        $stmt->bind_param("ss", $teacher_email, $detail_email);
        $stmt->execute();
        $detail_attempts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        $detail_section_ids = teacher_student_section_ids($conn, $teacher_email, $detail_email);
        $every_section = teacher_sections($conn, $teacher_email);
        $detail_shared = student_shared_with_others($conn, $teacher_email, $detail_email);
        $detail_managed = teacher_manages_account($conn, $teacher_email, $detail_email);
    }
}

$active = 'students';
$nav_base = '../';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../navStyle.css?v=10">
    <link rel="stylesheet" href="../appStyle.css?v=10">
    <title><?php echo $detail ? e($detail['full_name']) : 'Students'; ?></title>
</head>
<body>
    <?php include APP_ROOT . '/dashboards/partials/nav_teacher.php'; ?>
    <div class="container" id="main">

    <?php if ($detail_email !== '' && !$detail): ?>
        <div class="page-header">
            <div>
                <h1>Student not found</h1>
                <p class="subtitle">That student is not in your classes.</p>
            </div>
            <a href="student_profile.php" class="btn btn-outline">&larr; All Students</a>
        </div>

    <?php elseif ($detail): ?>
        <?php
        $scores = [];
        $pending_total = 0;
        foreach ($detail_attempts as $a) {
            if ((float) $a['max_score'] > 0 && $a['total_score'] !== null) {
                $scores[] = (float) $a['total_score'] / (float) $a['max_score'];
            }
            $pending_total += (int) $a['needs_manual_grading'];
        }
        $avg = $scores ? array_sum($scores) / count($scores) : null;
        $quiz_count = count(array_unique(array_column($detail_attempts, 'quiz_id')));
        ?>
        <div class="page-header">
            <div>
                <h1><?php echo e($detail['full_name']); ?></h1>
                <p class="subtitle">
                    <?php echo e($detail['email']); ?>
                    <?php if ($detail['id']): ?>&middot; ID <?php echo e($detail['id']); ?><?php endif; ?>
                    <?php if ($detail['must_change_password']): ?>&middot; <span class="badge badge-pending">Awaiting first login</span><?php endif; ?>
                </p>
            </div>
            <a href="student_profile.php" class="btn btn-outline">&larr; All Students</a>
        </div>

        <div class="card">
            <div class="stat-grid">
                <div class="stat-card">
                    <div class="stat-value"><?php echo $quiz_count; ?></div>
                    <div class="stat-label">Quizzes taken</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?php echo count($detail_attempts); ?></div>
                    <div class="stat-label">Submitted attempts</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?php echo $avg !== null ? round($avg * 100, 1) . '%' : '-'; ?></div>
                    <div class="stat-label">Average score</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?php echo $pending_total; ?></div>
                    <div class="stat-label">Answers to grade</div>
                </div>
            </div>
        </div>

        <?php if ($success): ?><div class="alert alert-success" role="status"><?php echo e($success); ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-error" role="alert"><?php echo e($error); ?></div><?php endif; ?>
        <?php if ($shared_password !== null): ?>
            <div class="alert alert-warning" role="status">
                <div class="shared-password">Default password: <code id="shared-password"><?php echo e($shared_password); ?></code></div>
                <small>Give it to the student privately. It is not shown again.</small>
            </div>
        <?php endif; ?>

        <div class="class-grid">
            <div class="card">
                <h2>Edit details</h2>
                <?php if (!$detail_managed): ?>
                    <p class="helptext">You didn't create this account, so its name, email and ID are managed by the student. You can change which of your sections they are in.</p>
                <?php elseif ($detail_shared): ?>
                    <p class="helptext">This student is also in another teacher's class. Name, email and ID changes apply to their one account; section changes only affect your sections.</p>
                <?php endif; ?>
                <form method="POST"><?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="update_student">
                    <input type="hidden" name="student_email" value="<?php echo e($detail['email']); ?>">
                    <?php $ro = $detail_managed ? '' : 'readonly'; ?>
                    <div class="form-field">
                        <label for="ed-name">Full name</label>
                        <input type="text" id="ed-name" name="full_name" maxlength="50" value="<?php echo e($detail['full_name']); ?>" required <?php echo $ro; ?>>
                    </div>
                    <div class="form-field">
                        <label for="ed-email">Email</label>
                        <input type="email" id="ed-email" name="email" maxlength="100" value="<?php echo e($detail['email']); ?>" required <?php echo $ro; ?>>
                    </div>
                    <div class="form-field">
                        <label for="ed-id">Student ID</label>
                        <input type="text" id="ed-id" name="student_id" maxlength="50" value="<?php echo e($detail['id'] ?? ''); ?>" <?php echo $ro; ?>>
                    </div>
                    <fieldset class="section-picker">
                        <legend>Sections</legend>
                        <?php if (!$every_section): ?>
                            <p class="helptext">No sections yet. Create them under <a class="link" href="classes.php">Classes</a>.</p>
                        <?php else: ?>
                            <div class="check-row">
                                <?php foreach ($every_section as $s): ?>
                                    <label class="form-check"><input type="checkbox" name="sections[]" value="<?php echo (int) $s['id']; ?>" <?php echo in_array((int) $s['id'], $detail_section_ids, true) ? 'checked' : ''; ?>> <?php echo e($s['name']); ?></label>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </fieldset>
                    <button type="submit" class="btn btn-block">Save details</button>
                </form>
            </div>

            <div class="card">
                <h2>Password</h2>
                <?php if ($detail_managed): ?>
                <p class="helptext">Forgot their password? Give them a default one; they must choose their own at next login.</p>
                <form method="POST" data-confirm="Set a new default password? The current password will stop working." data-danger><?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="reset_password">
                    <input type="hidden" name="student_email" value="<?php echo e($detail['email']); ?>">
                    <div class="form-field">
                        <label for="rp">Default password</label>
                        <input type="text" id="rp" name="default_password" minlength="<?php echo PASSWORD_MIN_LENGTH; ?>" maxlength="<?php echo PASSWORD_MAX_LENGTH; ?>" placeholder="Leave blank to generate" autocomplete="off">
                    </div>
                    <button type="submit" class="btn btn-block">Set default password</button>
                </form>
                <?php else: ?>
                <p class="helptext">This student created their own account. If they forget their password, they can reset it with "Forgot password" on the sign-in page.</p>
                <?php endif; ?>

                <h2 class="danger-title">Remove student</h2>
                <p class="helptext">
                    <?php echo ($detail_shared || !$detail_managed)
                        ? 'Removes them from your sections and deletes their results on your quizzes. Their account is kept.'
                        : 'Deletes the account with their results on your quizzes. This cannot be undone.'; ?>
                </p>
                <form method="POST" data-confirm="Remove <?php echo e($detail['email']); ?> and their results on your quizzes? This cannot be undone." data-danger><?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="delete_student">
                    <input type="hidden" name="student_email" value="<?php echo e($detail['email']); ?>">
                    <button type="submit" class="btn btn-sm btn-danger-outline">Remove student</button>
                </form>
            </div>
        </div>

        <div class="card">
            <h2>Attempts</h2>
            <?php if (empty($detail_attempts)): ?>
                <div class="empty-state">This student has not submitted any of your quizzes yet.</div>
            <?php else: ?>
                <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Quiz</th><th>Attempt</th><th>Submitted</th><th>Score</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                        <?php foreach ($detail_attempts as $a):
                            $pct = ((float) $a['max_score'] > 0 && $a['total_score'] !== null)
                                ? round((float) $a['total_score'] / (float) $a['max_score'] * 100, 1) . '%'
                                : '-';
                        ?>
                            <tr>
                                <td><?php echo e($a['quiz_title']); ?></td>
                                <td>#<?php echo (int) $a['attempt_number']; ?><?php if (attempt_is_late($a)): ?> <span class="badge badge-pending">Late</span><?php endif; ?></td>
                                <td><?php echo date('M j, Y g:i A', strtotime($a['submitted_at'])); ?></td>
                                <td><?php echo fmt_score($a['total_score']); ?> / <?php echo fmt_score($a['max_score']); ?> (<?php echo $pct; ?>)</td>
                                <td><?php echo $a['needs_manual_grading'] ? '<span class="badge badge-pending">Pending</span>' : '<span class="badge badge-graded">Graded</span>'; ?></td>
                                <td><a class="btn btn-sm <?php echo $a['needs_manual_grading'] ? '' : 'btn-outline'; ?>" href="grade_attempt.php?attempt_id=<?php echo (int) $a['id']; ?>"><?php echo $a['needs_manual_grading'] ? 'Evaluate' : 'View'; ?></a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            <?php endif; ?>
        </div>

    <?php else: ?>
        <?php
        $with_pending = count(array_filter($students, fn($s) => (int) $s['pending_count'] > 0));
        $not_taken = count(array_filter($students, fn($s) => (int) $s['attempts_taken'] === 0));
        ?>
        <div class="page-header">
            <div>
                <h1>Students</h1>
                <p class="subtitle"><?php echo count($students); ?> student(s) in your classes. Open a student to edit their details, sections or password.</p>
            </div>
            <div class="item-actions">
                <a href="classes.php#add-student" class="btn">Add / import students</a>
                <a href="student_profile.php?export=1" class="btn btn-outline">Export CSV</a>
            </div>
        </div>

        <div class="card">
            <div class="stat-grid">
                <div class="stat-card">
                    <div class="stat-value"><?php echo count($students); ?></div>
                    <div class="stat-label">Your students</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?php echo $not_taken; ?></div>
                    <div class="stat-label">Haven't attempted yet</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?php echo $with_pending; ?></div>
                    <div class="stat-label">Waiting for your grading</div>
                </div>
            </div>

            <?php if ($success): ?><div class="alert alert-success" role="status"><?php echo e($success); ?></div><?php endif; ?>
            <?php if ($error): ?><div class="alert alert-error" role="alert"><?php echo e($error); ?></div><?php endif; ?>
            <div class="filter-bar">
                <input type="search" id="student-search" placeholder="Search by name, email, ID or section" aria-label="Search students">
                <select id="student-filter" aria-label="Filter students">
                    <option value="all">All students</option>
                    <option value="pending">Waiting for grading</option>
                    <option value="none">Not attempted yet</option>
                </select>
            </div>

            <?php if (empty($students)): ?>
                <div class="empty-state">No students in your classes yet. Add them under <a class="link" href="classes.php">Classes</a>.</div>
            <?php else: ?>
                <div class="table-wrap">
                <table class="data-table" id="students-table" data-enhance="sort" aria-label="Students">
                    <thead>
                        <tr><th>Name</th><th>Email</th><th>ID</th><th>Sections</th><th>Quizzes</th><th>Attempts</th><th>Average</th><th>Last submitted</th><th>Grading</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($students as $s):
                            $state = (int) $s['pending_count'] > 0 ? 'pending' : ((int) $s['attempts_taken'] === 0 ? 'none' : 'done');
                        ?>
                            <tr data-state="<?php echo $state; ?>"
                                data-search="<?php echo e(mb_strtolower($s['full_name'] . ' ' . $s['email'] . ' ' . $s['id'] . ' ' . $s['sections'])); ?>">
                                <td><a class="link" href="student_profile.php?email=<?php echo urlencode($s['email']); ?>"><?php echo e($s['full_name']); ?></a></td>
                                <td class="cell-muted"><?php echo e($s['email']); ?></td>
                                <td><?php echo e($s['id'] ?? ''); ?></td>
                                <td><?php echo $s['sections'] !== '' ? e($s['sections']) : '<span class="cell-muted">-</span>'; ?></td>
                                <td><?php echo (int) $s['quizzes_taken']; ?></td>
                                <td><?php echo (int) $s['attempts_taken']; ?></td>
                                <td><?php echo $s['avg_pct'] !== null ? round($s['avg_pct'] * 100, 1) . '%' : '-'; ?></td>
                                <td><?php echo $s['last_submitted'] ? date('M j, Y', strtotime($s['last_submitted'])) : '-'; ?></td>
                                <td>
                                    <?php if ($state === 'pending'): ?>
                                        <span class="badge badge-pending"><?php echo (int) $s['pending_count']; ?> to grade</span>
                                    <?php elseif ($state === 'none'): ?>
                                        <span class="badge badge-draft">No attempts</span>
                                    <?php else: ?>
                                        <span class="badge badge-graded">Up to date</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <div class="empty-state" id="no-match" style="display:none;">No students match your search.</div>
            <?php endif; ?>
        </div>

        <script>
            (function () {
                const search = document.getElementById('student-search');
                const filter = document.getElementById('student-filter');
                const rows = document.querySelectorAll('#students-table tbody tr');
                const noMatch = document.getElementById('no-match');

                function apply() {
                    const q = search.value.trim().toLowerCase();
                    const state = filter.value;
                    let shown = 0;
                    rows.forEach(row => {
                        const matchText = !q || row.dataset.search.includes(q);
                        const matchState = state === 'all' || row.dataset.state === state;
                        const visible = matchText && matchState;
                        row.style.display = visible ? '' : 'none';
                        if (visible) shown++;
                    });
                    if (noMatch) noMatch.style.display = shown === 0 ? 'block' : 'none';
                }

                search.addEventListener('input', apply);
                filter.addEventListener('change', apply);
            })();
        </script>
    <?php endif; ?>

    </div>
</body>
</html>
