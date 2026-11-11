<?php
// A teacher's own classes: their sections and the students in them. Students
// are registered here (CSV upload or manual entry) with a default password
// they must change at first login. Teachers only ever see their own sections.
require_once __DIR__ . '/../../includes/bootstrap.php';

require_role('teacher', '../../auth/login.php');

$teacher_email = current_user_email();
const CSV_MAX_BYTES = 1048576; // 1 MB is thousands of students

// --- Sample CSV --------------------------------------------------------------
if (isset($_GET['sample'])) {
    send_csv('students_sample.csv', ['name', 'email', 'id', 'section'], [
        ['Rafi Ahmed', 'rafi@example.edu', '2022-1-60-001', 'CSE-101 Section A'],
        ['Nusrat Khan', 'nusrat@example.edu', '2022-1-60-002', 'CSE-101 Section B'],
    ]);
}

$section_id = (int) ($_GET['section_id'] ?? $_POST['section_id'] ?? 0);
$section = $section_id ? find_teacher_section($conn, $teacher_email, $section_id) : null;
if ($section_id && !$section) {
    header("Location: classes.php");
    exit;
}

$success = "";
$error = "";
$shared_password = null; // shown once so the teacher can pass it on
$report = null;

/** Default password from the form, or a generated one. Sets $error on a bad value. */
function chosen_default_password(string &$error): ?string
{
    $p = trim((string) ($_POST['default_password'] ?? ''));
    if ($p === '') {
        return generate_default_password();
    }
    if ($rule = password_rule_error($p)) {
        $error = "Default password: $rule";
        return null;
    }
    return $p;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'create_section') {
            $name = clean_section_name((string) ($_POST['name'] ?? ''));
            if ($name === '' || mb_strlen($name) > SECTION_NAME_MAX) {
                $error = "Section name must be 1-" . SECTION_NAME_MAX . " characters.";
            } else {
                $new_id = ensure_section($conn, $teacher_email, $name);
                header("Location: classes.php?section_id=$new_id&created=1");
                exit;
            }
        } elseif ($action === 'rename_section' && $section) {
            rename_section($conn, $teacher_email, (int) $section['id'], (string) ($_POST['name'] ?? ''));
            $success = "Section renamed.";
        } elseif ($action === 'delete_section' && $section) {
            delete_section($conn, $teacher_email, (int) $section['id']);
            header("Location: classes.php?deleted=1");
            exit;
        } elseif ($action === 'import_csv') {
            $file = $_FILES['csv'] ?? null;
            $password = chosen_default_password($error);
            if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
                $error = "Choose a CSV or Excel file to upload.";
            } elseif ($file['size'] > CSV_MAX_BYTES) {
                $error = "The file is too large (max 1 MB).";
            } elseif (!preg_match('/\.(csv|tsv|txt|xlsx|xlsm|xls)$/i', (string) $file['name'])) {
                $error = "Upload a CSV or Excel file (.csv, .xlsx, .xls, .txt).";
            } elseif ($password !== null) {
                $parsed = parse_student_csv((string) file_get_contents($file['tmp_name']));
                if ($parsed['errors']) {
                    $error = implode(' ', $parsed['errors']);
                } elseif (!$parsed['rows']) {
                    $error = "The file has no student rows.";
                } else {
                    $fallback = $section ? (int) $section['id'] : (((int) ($_POST['fallback_section'] ?? 0)) ?: null);
                    if ($fallback !== null && !find_teacher_section($conn, $teacher_email, $fallback)) {
                        $fallback = null;
                    }
                    $report = import_students($conn, $teacher_email, $parsed['rows'], $password, $fallback);
                    if ($report['created'] > 0) {
                        $shared_password = $password;
                    }
                }
            }
        } elseif ($action === 'add_student') {
            $password = chosen_default_password($error);
            $section_name = $section ? $section['name'] : (string) ($_POST['section'] ?? '');
            if ($password !== null) {
                $report = import_students($conn, $teacher_email, [[
                    'line' => 1,
                    'name' => (string) ($_POST['name'] ?? ''),
                    'email' => (string) ($_POST['email'] ?? ''),
                    'id' => (string) ($_POST['student_id'] ?? ''),
                    'section' => $section_name,
                ]], $password);
                if ($report['skipped']) {
                    $error = reset($report['skipped']);
                    $report = null;
                } else {
                    $success = $report['created']
                        ? "Student registered and added to the section."
                        : ($report['enrolled'] ? "Existing student added to the section. Their password was not changed." : "That student is already in the section.");
                    if ($report['created']) {
                        $shared_password = $password;
                    }
                    $report = null;
                }
            }
        } elseif ($action === 'reset_password') {
            $password = chosen_default_password($error);
            $student = trim((string) ($_POST['student_email'] ?? ''));
            if ($password !== null) {
                if (reset_student_password($conn, $teacher_email, $student, $password)) {
                    $success = "Default password set for $student. They must choose a new password when they log in.";
                    $shared_password = $password;
                } else {
                    $error = "You can only reset passwords for student accounts you created. Students who signed up themselves can use \"Forgot password\".";
                }
            }
        } elseif ($action === 'remove_student' && $section) {
            $student = trim((string) ($_POST['student_email'] ?? ''));
            if (remove_from_section($conn, $teacher_email, (int) $section['id'], $student)) {
                $success = "$student was removed from this section. Their account and results are kept.";
            }
        }
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    }
}

if (isset($_GET['created'])) {
    $success = $success ?: "Section created. Add students below.";
}
if (isset($_GET['deleted'])) {
    $success = $success ?: "Section deleted. Student accounts and results are kept.";
}

$sections = teacher_sections($conn, $teacher_email);
$every_section = $sections;
if ($section) {
    $section = find_teacher_section($conn, $teacher_email, (int) $section['id']);
    $roster = section_roster($conn, (int) $section['id']);

    // Section roster as CSV.
    if (isset($_GET['export'])) {
        send_csv($section['name'] . '_students.csv', ['name', 'email', 'id', 'section', 'status'],
            array_map(fn($r) => [$r['full_name'], $r['email'], $r['id'], $section['name'],
                $r['must_change_password'] ? 'Awaiting first login' : 'Active'], $roster));
    }
}

$active = 'classes';
$nav_base = '../';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../navStyle.css?v=10">
    <link rel="stylesheet" href="../appStyle.css?v=10">
    <title><?php echo $section ? e($section['name']) : 'Classes'; ?></title>
</head>

<body>
    <?php include APP_ROOT . '/dashboards/partials/nav_teacher.php'; ?>

    <div class="container" id="main">
        <div class="page-header">
            <div>
                <h1><?php echo $section ? e($section['name']) : 'Classes'; ?></h1>
                <p class="subtitle">
                    <?php echo $section
                        ? count($roster) . ' student(s) in this section.'
                        : 'Create sections, register students by CSV or by hand, and reset student passwords.'; ?>
                </p>
            </div>
            <?php if ($section): ?>
                <div class="item-actions">
                    <a href="classes.php" class="btn btn-outline">&larr; All sections</a>
                    <a href="classes.php?section_id=<?php echo (int) $section['id']; ?>&amp;export=1" class="btn btn-outline">Export CSV</a>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($success): ?><div class="alert alert-success" role="status"><?php echo e($success); ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-error" role="alert"><?php echo e($error); ?></div><?php endif; ?>

        <?php if ($report !== null): ?>
            <div class="alert <?php echo $report['skipped'] ? 'alert-info' : 'alert-success'; ?>" role="status">
                <strong>Import finished.</strong>
                <?php echo (int) $report['created']; ?> new account(s) created,
                <?php echo (int) $report['enrolled']; ?> added to a section,
                <?php echo (int) $report['already']; ?> already enrolled,
                <?php echo count($report['skipped']); ?> skipped.
                <?php if ($report['skipped']): ?>
                    <ul class="import-skipped">
                        <?php foreach ($report['skipped'] as $line => $reason): ?>
                            <li>Line <?php echo (int) $line; ?>: <?php echo e($reason); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($shared_password !== null): ?>
            <div class="alert alert-warning" role="status">
                <div class="shared-password">
                    Default password: <code id="shared-password"><?php echo e($shared_password); ?></code>
                    <button type="button" class="btn btn-sm btn-outline" data-copy="#shared-password">Copy</button>
                </div>
                <small>Give it to the new student(s) privately. They must choose their own password at first login. It is not shown again.</small>
            </div>
        <?php endif; ?>

        <?php if (!$section): ?>
            <div class="card">
                <h2>My sections</h2>
                <?php if (!$sections): ?>
                    <div class="empty-state">No sections yet. Create one below, or import a CSV with a section column.</div>
                <?php else: ?>
                    <div class="card-list">
                        <?php foreach ($sections as $s): ?>
                            <div class="item-row">
                                <div>
                                    <div class="item-title"><?php echo e($s['name']); ?></div>
                                    <div class="item-meta"><?php echo (int) $s['student_count']; ?> student(s)</div>
                                </div>
                                <div class="item-actions">
                                    <a class="btn btn-sm" href="classes.php?section_id=<?php echo (int) $s['id']; ?>">Open</a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <form method="POST" class="inline-create"><?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="create_section">
                    <div class="form-field">
                        <label for="new-section">New section</label>
                        <div class="input-with-button">
                            <input type="text" id="new-section" name="name" maxlength="<?php echo SECTION_NAME_MAX; ?>" placeholder="e.g. CSE-101 Section A" required>
                            <button type="submit" class="btn btn-sm">Create</button>
                        </div>
                    </div>
                </form>
            </div>
        <?php endif; ?>

        <div class="class-grid">
            <div class="card">
                <h2>Import from CSV or Excel</h2>
                <p class="helptext">
                    Columns: <code>name, email, id, section</code> (header row optional, any order). Excel workbooks (.xlsx) and any CSV or text export from Excel work.
                    New students get an account with the default password; existing students are just added to the section.
                    <a class="link" href="classes.php?sample=1">Download a sample CSV</a>
                </p>
                <form method="POST" enctype="multipart/form-data"><?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="import_csv">
                    <?php if ($section): ?><input type="hidden" name="section_id" value="<?php echo (int) $section['id']; ?>"><?php endif; ?>
                    <div class="form-field">
                        <label for="csv">CSV or Excel file</label>
                        <input type="file" id="csv" name="csv" accept=".csv,.tsv,.txt,.xlsx,.xlsm,.xls,text/csv,text/plain,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel" required>
                    </div>
                    <?php if (!$section && $every_section): ?>
                        <div class="form-field">
                            <label for="fallback-section">Section for rows without one</label>
                            <select id="fallback-section" name="fallback_section">
                                <option value="">None (skip those rows)</option>
                                <?php foreach ($every_section as $s): ?>
                                    <option value="<?php echo (int) $s['id']; ?>"><?php echo e($s['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php elseif ($section): ?>
                        <p class="helptext">Rows without a section go into <strong><?php echo e($section['name']); ?></strong>.</p>
                    <?php endif; ?>
                    <div class="form-field">
                        <label for="import-password">Default password for new students</label>
                        <div class="input-with-button">
                            <input type="text" id="import-password" name="default_password" minlength="6" maxlength="64" placeholder="Leave blank to generate" autocomplete="off">
                            <button type="button" class="btn btn-outline btn-sm" data-generate="#import-password">Generate</button>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-block">Import students</button>
                </form>
            </div>

            <div class="card" id="add-student">
                <h2>Add one student</h2>
                <p class="helptext">Registers the student if they are new, then adds them to the section.</p>
                <form method="POST"><?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="add_student">
                    <?php if ($section): ?><input type="hidden" name="section_id" value="<?php echo (int) $section['id']; ?>"><?php endif; ?>
                    <div class="form-field">
                        <label for="s-name">Full name</label>
                        <input type="text" id="s-name" name="name" maxlength="50" required>
                    </div>
                    <div class="form-field">
                        <label for="s-email">Email</label>
                        <input type="email" id="s-email" name="email" maxlength="100" required>
                    </div>
                    <div class="form-field">
                        <label for="s-id">Student ID</label>
                        <input type="text" id="s-id" name="student_id" maxlength="50" placeholder="Optional">
                    </div>
                    <?php if (!$section): ?>
                        <div class="form-field">
                            <label for="s-section">Section</label>
                            <input type="text" id="s-section" name="section" list="section-names" maxlength="<?php echo SECTION_NAME_MAX; ?>" placeholder="Existing or new section" required>
                            <datalist id="section-names">
                                <?php foreach ($every_section as $s): ?><option value="<?php echo e($s['name']); ?>"></option><?php endforeach; ?>
                            </datalist>
                        </div>
                    <?php endif; ?>
                    <div class="form-field">
                        <label for="s-password">Default password</label>
                        <div class="input-with-button">
                            <input type="text" id="s-password" name="default_password" minlength="6" maxlength="64" placeholder="Leave blank to generate" autocomplete="off">
                            <button type="button" class="btn btn-outline btn-sm" data-generate="#s-password">Generate</button>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-block">Add student</button>
                </form>
            </div>
        </div>

        <?php if ($section): ?>
            <div class="card">
                <h2>Students</h2>
                <?php if (!$roster): ?>
                    <div class="empty-state">No students in this section yet.</div>
                <?php else: ?>
                    <div class="filter-bar">
                        <input type="search" id="roster-search" placeholder="Search by name, email or ID" aria-label="Search students">
                    </div>
                    <div class="table-wrap">
                        <table class="data-table" id="roster-table" data-enhance="sort" aria-label="Section roster">
                            <thead><tr><th>Name</th><th>Email</th><th>ID</th><th>Status</th><th>Actions</th></tr></thead>
                            <tbody>
                                <?php foreach ($roster as $r): ?>
                                    <tr data-search="<?php echo e(mb_strtolower($r['full_name'] . ' ' . $r['email'] . ' ' . $r['id'])); ?>">
                                        <td><a class="link" href="student_profile.php?email=<?php echo urlencode($r['email']); ?>"><?php echo e($r['full_name']); ?></a></td>
                                        <td><?php echo e($r['email']); ?></td>
                                        <td><?php echo e($r['id'] ?? ''); ?></td>
                                        <td><?php echo $r['must_change_password'] ? '<span class="badge badge-pending">Awaiting first login</span>' : '<span class="badge badge-graded">Active</span>'; ?></td>
                                        <td class="row-actions">
                                            <?php if ($r['managed']): ?>
                                            <form method="POST" data-confirm="Give <?php echo e($r['email']); ?> a new default password? Their current password will stop working." data-danger><?php echo csrf_field(); ?>
                                                <input type="hidden" name="action" value="reset_password">
                                                <input type="hidden" name="section_id" value="<?php echo (int) $section['id']; ?>">
                                                <input type="hidden" name="student_email" value="<?php echo e($r['email']); ?>">
                                                <button type="submit" class="btn btn-sm btn-outline">Reset password</button>
                                            </form>
                                            <?php endif; ?>
                                            <form method="POST" data-confirm="Remove <?php echo e($r['email']); ?> from this section? Their account and results are kept." data-danger><?php echo csrf_field(); ?>
                                                <input type="hidden" name="action" value="remove_student">
                                                <input type="hidden" name="section_id" value="<?php echo (int) $section['id']; ?>">
                                                <input type="hidden" name="student_email" value="<?php echo e($r['email']); ?>">
                                                <button type="submit" class="btn btn-sm btn-danger-outline">Remove</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <p class="empty-state" id="no-match" hidden>No students match your search.</p>
                <?php endif; ?>
            </div>

            <div class="card">
                <h2>Rename section</h2>
                <form method="POST"><?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="rename_section">
                    <input type="hidden" name="section_id" value="<?php echo (int) $section['id']; ?>">
                    <div class="form-field">
                        <label for="rename">Section name</label>
                        <div class="input-with-button">
                            <input type="text" id="rename" name="name" maxlength="<?php echo SECTION_NAME_MAX; ?>" value="<?php echo e($section['name']); ?>" required>
                            <button type="submit" class="btn btn-sm">Save</button>
                        </div>
                    </div>
                </form>
            </div>

            <div class="card danger-zone">
                <h2>Delete section</h2>
                <p class="helptext">Removes the section and its quiz assignments. Student accounts and their results are kept.</p>
                <form method="POST" data-confirm="Delete the section <?php echo e($section['name']); ?>?" data-danger><?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="delete_section">
                    <input type="hidden" name="section_id" value="<?php echo (int) $section['id']; ?>">
                    <button type="submit" class="btn btn-sm btn-danger-outline">Delete section</button>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <script>
        (function () {
            // Readable random password, matching generate_default_password() on the server.
            function generate() {
                const sets = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnpqrstuvwxyz', '23456789'];
                const all = sets.join('');
                const pick = s => s[crypto.getRandomValues(new Uint32Array(1))[0] % s.length];
                const chars = sets.map(pick);
                while (chars.length < 10) chars.push(pick(all));
                for (let i = chars.length - 1; i > 0; i--) {
                    const j = crypto.getRandomValues(new Uint32Array(1))[0] % (i + 1);
                    [chars[i], chars[j]] = [chars[j], chars[i]];
                }
                return chars.join('');
            }
            document.querySelectorAll('[data-generate]').forEach(btn => btn.addEventListener('click', () => {
                document.querySelector(btn.dataset.generate).value = generate();
            }));
            document.querySelectorAll('[data-copy]').forEach(btn => btn.addEventListener('click', () => {
                const text = document.querySelector(btn.dataset.copy).textContent;
                if (navigator.clipboard) navigator.clipboard.writeText(text).then(() => { btn.textContent = 'Copied'; });
            }));
            const search = document.getElementById('roster-search');
            if (search) {
                search.addEventListener('input', () => {
                    const q = search.value.trim().toLowerCase();
                    let shown = 0;
                    document.querySelectorAll('#roster-table tbody tr').forEach(tr => {
                        const ok = !q || tr.dataset.search.includes(q);
                        tr.hidden = !ok;
                        if (ok) shown++;
                    });
                    document.getElementById('no-match').hidden = shown > 0;
                });
            }
        })();
    </script>
</body>

</html>
