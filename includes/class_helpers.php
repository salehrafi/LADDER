<?php
/**
 * Classes: each teacher's own sections, students and default passwords.
 *
 * Every teacher works in their own environment and is the administrator of
 * it: they create their sections, register and manage the students in them,
 * and run their own quizzes. A teacher never sees or changes another
 * teacher's sections, quizzes or results.
 *
 * "My students" = students in one of my sections, or who submitted one of my
 * quizzes. A student account can belong to several teachers' classes (for
 * example the same student in two courses); each teacher only sees their own
 * classes and results for that student.
 */

require_once __DIR__ . '/spreadsheet.php';

const SECTION_NAME_MAX = 80;

// ---- Default passwords ---------------------------------------------------------

/** A readable random password (no 0/O/1/l/I), 10 characters, always within the password rule. */
function generate_default_password(): string
{
    $upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    $lower = 'abcdefghijkmnpqrstuvwxyz';
    $digits = '23456789';
    $all = $upper . $lower . $digits;

    $chars = [
        $upper[random_int(0, strlen($upper) - 1)],
        $lower[random_int(0, strlen($lower) - 1)],
        $digits[random_int(0, strlen($digits) - 1)],
    ];
    while (count($chars) < 10) {
        $chars[] = $all[random_int(0, strlen($all) - 1)];
    }
    // Fisher-Yates with a CSPRNG (shuffle() is not cryptographically secure).
    for ($i = count($chars) - 1; $i > 0; $i--) {
        $j = random_int(0, $i);
        [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
    }
    return implode('', $chars);
}

/** The user chose their own password: store it and clear the forced-change flag. */
function complete_password_change(mysqli $conn, string $email, string $new_password): void
{
    $hash = password_hash($new_password, PASSWORD_DEFAULT);
    $stmt = $conn->prepare("UPDATE reg_info SET pass = ?, must_change_password = 0 WHERE email = ?");
    $stmt->bind_param("ss", $hash, $email);
    $stmt->execute();
    $stmt->close();
    // Other sessions using the old password end; this one stays signed in.
    bump_auth_version($conn, $email, true);
}

/** A reg_info row (without the password hash) by email, or null. */
function find_user(mysqli $conn, string $email): ?array
{
    $stmt = $conn->prepare("SELECT user_id, id, full_name, email, role, must_change_password FROM reg_info WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

// ---- Sections (always the teacher's own) -------------------------------------------

/** @return array<int,array> the teacher's sections with student counts, by name */
function teacher_sections(mysqli $conn, string $teacher_email): array
{
    $stmt = $conn->prepare(
        "SELECT s.id, s.name, s.created_at, COUNT(ss.student_email) AS student_count
         FROM sections s LEFT JOIN section_students ss ON ss.section_id = s.id
         WHERE s.teacher_email = ?
         GROUP BY s.id, s.name, s.created_at
         ORDER BY s.name ASC"
    );
    $stmt->bind_param("s", $teacher_email);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/** A section row if it belongs to this teacher, else null. */
function find_teacher_section(mysqli $conn, string $teacher_email, int $section_id): ?array
{
    $stmt = $conn->prepare("SELECT * FROM sections WHERE id = ? AND teacher_email = ?");
    $stmt->bind_param("is", $section_id, $teacher_email);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/** Normalise a section name: trimmed, single spaces. */
function clean_section_name(string $name): string
{
    return trim(preg_replace('/\s+/u', ' ', $name));
}

/**
 * Find the teacher's own section by name (case-insensitive) or create it.
 *
 * @return int section id
 */
function ensure_section(mysqli $conn, string $teacher_email, string $name): int
{
    $name = clean_section_name($name);
    if ($name === '' || mb_strlen($name) > SECTION_NAME_MAX) {
        throw new InvalidArgumentException('Section name must be 1-' . SECTION_NAME_MAX . ' characters.');
    }
    $stmt = $conn->prepare("SELECT id FROM sections WHERE teacher_email = ? AND LOWER(name) = LOWER(?)");
    $stmt->bind_param("ss", $teacher_email, $name);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) {
        return (int) $row['id'];
    }

    $stmt = $conn->prepare("INSERT INTO sections (teacher_email, name) VALUES (?, ?)");
    $stmt->bind_param("ss", $teacher_email, $name);
    $stmt->execute();
    $id = (int) $stmt->insert_id;
    $stmt->close();
    return $id;
}

/** Rename one of the teacher's sections. */
function rename_section(mysqli $conn, string $teacher_email, int $section_id, string $name): void
{
    $name = clean_section_name($name);
    if ($name === '' || mb_strlen($name) > SECTION_NAME_MAX) {
        throw new InvalidArgumentException('Section name must be 1-' . SECTION_NAME_MAX . ' characters.');
    }
    $stmt = $conn->prepare("UPDATE sections SET name = ? WHERE id = ? AND teacher_email = ?");
    $stmt->bind_param("sis", $name, $section_id, $teacher_email);
    try {
        $stmt->execute();
    } catch (mysqli_sql_exception $e) {
        if ($e->getCode() === 1062) {
            throw new InvalidArgumentException('You already have a section with that name.');
        }
        throw $e;
    } finally {
        $stmt->close();
    }
}

/** Delete one of the teacher's sections (enrolments and quiz assignments go with it; accounts stay). */
function delete_section(mysqli $conn, string $teacher_email, int $section_id): bool
{
    $stmt = $conn->prepare("DELETE FROM sections WHERE id = ? AND teacher_email = ?");
    $stmt->bind_param("is", $section_id, $teacher_email);
    $stmt->execute();
    $deleted = $stmt->affected_rows === 1;
    $stmt->close();
    return $deleted;
}

/** @return array<int,array> students in a section (caller checks the section is the teacher's) */
function section_roster(mysqli $conn, int $section_id): array
{
    $stmt = $conn->prepare(
        "SELECT r.id, r.full_name, r.email, r.must_change_password, ss.added_at,
                (r.managed_by <=> t.user_id) AS managed
         FROM section_students ss
         JOIN reg_info r ON r.email = ss.student_email
         JOIN sections s ON s.id = ss.section_id
         JOIN reg_info t ON t.email = s.teacher_email
         WHERE ss.section_id = ?
         ORDER BY r.full_name ASC"
    );
    $stmt->bind_param("i", $section_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function remove_from_section(mysqli $conn, string $teacher_email, int $section_id, string $student_email): bool
{
    $stmt = $conn->prepare(
        "DELETE ss FROM section_students ss JOIN sections s ON s.id = ss.section_id
         WHERE ss.section_id = ? AND ss.student_email = ? AND s.teacher_email = ?"
    );
    $stmt->bind_param("iss", $section_id, $student_email, $teacher_email);
    $stmt->execute();
    $removed = $stmt->affected_rows === 1;
    $stmt->close();
    return $removed;
}

// ---- The teacher's students ------------------------------------------------------

/** Is this a student of this teacher (in one of their sections, or took one of their quizzes)? */
function teacher_has_student(mysqli $conn, string $teacher_email, string $student_email): bool
{
    $stmt = $conn->prepare(
        "SELECT 1 FROM reg_info r WHERE r.email = ? AND r.role = 'student' AND (
            EXISTS (SELECT 1 FROM section_students ss JOIN sections s ON s.id = ss.section_id
                    WHERE ss.student_email = r.email AND s.teacher_email = ?)
            OR EXISTS (SELECT 1 FROM quiz_attempts qa JOIN quizzes q ON q.id = qa.quiz_id
                       WHERE qa.student_email = r.email AND q.teacher_email = ?))"
    );
    $stmt->bind_param("sss", $student_email, $teacher_email, $teacher_email);
    $stmt->execute();
    $has = $stmt->get_result()->num_rows === 1;
    $stmt->close();
    return $has;
}

/**
 * Did this teacher create the student's account? Only then may they change
 * its name, email or ID, reset its password or delete it. Enrolling a student
 * in a section gives no such rights.
 */
function teacher_manages_account(mysqli $conn, string $teacher_email, string $student_email): bool
{
    $stmt = $conn->prepare(
        "SELECT 1 FROM reg_info s JOIN reg_info t ON t.user_id = s.managed_by
         WHERE s.email = ? AND s.role = 'student' AND t.email = ? AND t.role = 'teacher'"
    );
    $stmt->bind_param("ss", $student_email, $teacher_email);
    $stmt->execute();
    $manages = $stmt->get_result()->num_rows === 1;
    $stmt->close();
    return $manages;
}

/** Does another teacher also have this student (their class or their quiz)? */
function student_shared_with_others(mysqli $conn, string $teacher_email, string $student_email): bool
{
    $stmt = $conn->prepare(
        "SELECT 1 FROM section_students ss JOIN sections s ON s.id = ss.section_id
         WHERE ss.student_email = ? AND s.teacher_email <> ?
         UNION
         SELECT 1 FROM quiz_attempts qa JOIN quizzes q ON q.id = qa.quiz_id
         WHERE qa.student_email = ? AND q.teacher_email <> ?
         LIMIT 1"
    );
    $stmt->bind_param("ssss", $student_email, $teacher_email, $student_email, $teacher_email);
    $stmt->execute();
    $shared = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $shared;
}

/** @return int[] ids of the teacher's sections this student is in */
function teacher_student_section_ids(mysqli $conn, string $teacher_email, string $student_email): array
{
    $stmt = $conn->prepare(
        "SELECT ss.section_id FROM section_students ss JOIN sections s ON s.id = ss.section_id
         WHERE ss.student_email = ? AND s.teacher_email = ?"
    );
    $stmt->bind_param("ss", $student_email, $teacher_email);
    $stmt->execute();
    $ids = array_map('intval', array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'section_id'));
    $stmt->close();
    return $ids;
}

/** Set which of the teacher's sections a student is in. Other teachers' sections are untouched. */
function set_student_sections(mysqli $conn, string $teacher_email, string $student_email, array $section_ids): void
{
    $own = array_map('intval', array_column(teacher_sections($conn, $teacher_email), 'id'));
    $section_ids = array_values(array_intersect(array_map('intval', $section_ids), $own));

    $del = $conn->prepare(
        "DELETE ss FROM section_students ss JOIN sections s ON s.id = ss.section_id
         WHERE ss.student_email = ? AND s.teacher_email = ?"
    );
    $del->bind_param("ss", $student_email, $teacher_email);
    $del->execute();
    $del->close();

    $ins = $conn->prepare("INSERT INTO section_students (section_id, student_email) VALUES (?, ?)");
    foreach ($section_ids as $sid) {
        $ins->bind_param("is", $sid, $student_email);
        $ins->execute();
    }
    $ins->close();
}

/** The teacher's section names per student email, e.g. ['a@x' => 'Sec A, Sec B']. */
function section_names_by_student(mysqli $conn, string $teacher_email): array
{
    $stmt = $conn->prepare(
        "SELECT ss.student_email, GROUP_CONCAT(s.name ORDER BY s.name SEPARATOR ', ') AS names
         FROM section_students ss JOIN sections s ON s.id = ss.section_id
         WHERE s.teacher_email = ?
         GROUP BY ss.student_email"
    );
    $stmt->bind_param("s", $teacher_email);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return array_column($rows, 'names', 'student_email');
}

/** Validate account fields. Returns an error message or null. */
function account_field_error(string $name, string $email, string $id): ?string
{
    if ($name === '' || mb_strlen($name) > 50) {
        return 'Name is required (up to 50 characters).';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100) {
        return 'Enter a valid email address.';
    }
    if (mb_strlen($id) > 50) {
        return 'ID must be 50 characters or fewer.';
    }
    return null;
}

/**
 * Change one of the teacher's students: name, email and ID. A new email is
 * carried over to everything that references it by the foreign keys.
 */
function update_student(mysqli $conn, string $teacher_email, string $old_email, string $name, string $new_email, string $id): void
{
    $name = trim($name);
    $new_email = mb_strtolower(trim($new_email));
    $id = trim($id);
    if ($err = account_field_error($name, $new_email, $id)) {
        throw new InvalidArgumentException($err);
    }
    if (!teacher_manages_account($conn, $teacher_email, $old_email)) {
        throw new InvalidArgumentException('Only the teacher who created this account can change its details.');
    }
    $id_value = $id === '' ? null : $id;

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("UPDATE reg_info SET full_name = ?, email = ?, id = ? WHERE email = ? AND role = 'student'");
        $stmt->bind_param("ssss", $name, $new_email, $id_value, $old_email);
        $stmt->execute();
        $stmt->close();
        // A new email reaches attempts and enrolments through the foreign
        // keys (ON UPDATE CASCADE).
        $conn->commit();
    } catch (mysqli_sql_exception $e) {
        $conn->rollback();
        if ($e->getCode() === 1062) {
            throw new InvalidArgumentException('That email or ID is already used by another account.');
        }
        throw $e;
    }
}

/**
 * Give one of the teacher's students a default password (new account, or they
 * forgot it). They must choose their own at next login.
 *
 * @return bool false if this teacher did not create the account
 */
function reset_student_password(mysqli $conn, string $teacher_email, string $student_email, string $password): bool
{
    if (!teacher_manages_account($conn, $teacher_email, $student_email)) {
        return false;
    }
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $conn->prepare(
        "UPDATE reg_info SET pass = ?, must_change_password = 1, otp = NULL, exp_time = NULL
         WHERE email = ? AND role = 'student'"
    );
    $stmt->bind_param("ss", $hash, $student_email);
    $stmt->execute();
    $stmt->close();
    // Sign the student out everywhere; they log in again with the new password.
    bump_auth_version($conn, $student_email);
    return true;
}

/**
 * Remove a student from the teacher's environment: out of all the teacher's
 * sections and their results on the teacher's quizzes deleted. The account
 * itself is deleted only if this teacher created it and no other teacher has
 * the student.
 *
 * @return string 'deleted' (account removed), 'removed' (account kept) or 'not_found'
 */
function remove_student(mysqli $conn, string $teacher_email, string $student_email): string
{
    if (!teacher_has_student($conn, $teacher_email, $student_email)) {
        return 'not_found';
    }
    $shared = student_shared_with_others($conn, $teacher_email, $student_email)
        || !teacher_manages_account($conn, $teacher_email, $student_email);

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare(
            "DELETE ss FROM section_students ss JOIN sections s ON s.id = ss.section_id
             WHERE ss.student_email = ? AND s.teacher_email = ?"
        );
        $stmt->bind_param("ss", $student_email, $teacher_email);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare(
            "DELETE qa FROM quiz_attempts qa JOIN quizzes q ON q.id = qa.quiz_id
             WHERE qa.student_email = ? AND q.teacher_email = ?"
        );
        $stmt->bind_param("ss", $student_email, $teacher_email);
        $stmt->execute();
        $stmt->close();

        if (!$shared) {
            $stmt = $conn->prepare("DELETE FROM reg_info WHERE email = ? AND role = 'student'");
            $stmt->bind_param("s", $student_email);
            $stmt->execute();
            $stmt->close();
        }
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
    return $shared ? 'removed' : 'deleted';
}

// ---- Importing students ------------------------------------------------------------

/**
 * Parse an uploaded student list: an Excel workbook (.xlsx) or CSV/text in any
 * encoding, separated by commas, semicolons or tabs (see includes/spreadsheet.php).
 * The header row names the columns in any order: name, email, id (or
 * "student id"), section. Without a header the order name, email, id, section
 * is assumed.
 *
 * @return array{rows: array<int,array{line:int,name:string,email:string,id:string,section:string}>, errors: string[]}
 */
function parse_student_csv(string $content): array
{
    $sheet = read_spreadsheet($content);
    if ($sheet['error'] !== null) {
        return ['rows' => [], 'errors' => [$sheet['error']]];
    }
    $table = $sheet['rows'];
    if (!$table) {
        return ['rows' => [], 'errors' => ['The file is empty.']];
    }

    $aliases = [
        'name' => ['name', 'full name', 'full_name', 'student name'],
        'email' => ['email', 'e-mail', 'email address'],
        'id' => ['id', 'student id', 'student_id', 'roll', 'roll no'],
        'section' => ['section', 'class', 'section name'],
    ];

    $first = array_map(fn($c) => mb_strtolower(trim((string) $c)), $table[0]);
    $map = [];
    foreach ($aliases as $field => $names) {
        foreach ($first as $i => $col) {
            if (in_array($col, $names, true)) {
                $map[$field] = $i;
                break;
            }
        }
    }

    $has_header = isset($map['email']) || isset($map['name']);
    if (!$has_header) {
        $map = ['name' => 0, 'email' => 1, 'id' => 2, 'section' => 3];
    } elseif (!isset($map['name'], $map['email'])) {
        return ['rows' => [], 'errors' => ['The header must include at least "name" and "email" columns.']];
    }

    $rows = [];
    foreach (array_slice($table, $has_header ? 1 : 0) as $i => $cells) {
        $get = fn($f) => isset($map[$f]) ? trim((string) ($cells[$map[$f]] ?? '')) : '';
        $rows[] = [
            'line' => $i + ($has_header ? 2 : 1),
            'name' => $get('name'),
            'email' => mb_strtolower($get('email')),
            'id' => $get('id'),
            'section' => $get('section'),
        ];
    }
    return ['rows' => $rows, 'errors' => []];
}

/**
 * Register students and enrol them in the teacher's own sections.
 *
 * - New email: a student account is created with $default_password and must
 *   change it at first login.
 * - Existing student (even one another teacher also teaches): just added to
 *   this teacher's section; their password is untouched.
 * - A teacher's email, invalid data, or an ID used by someone else: the row
 *   is skipped with a reason.
 * Rows without a section go to $fallback_section_id (when given, and it must
 * be the teacher's own section).
 *
 * @param array<int,array{line:int,name:string,email:string,id:string,section:string}> $rows
 * @return array{created:int, enrolled:int, already:int, skipped: array<int,string>}
 */
function import_students(mysqli $conn, string $teacher_email, array $rows, string $default_password, ?int $fallback_section_id = null): array
{
    if ($fallback_section_id !== null && !find_teacher_section($conn, $teacher_email, $fallback_section_id)) {
        $fallback_section_id = null;
    }
    $report = ['created' => 0, 'enrolled' => 0, 'already' => 0, 'skipped' => []];
    $hash = password_hash($default_password, PASSWORD_DEFAULT);
    $seen = [];

    $find_user = $conn->prepare("SELECT email, role FROM reg_info WHERE email = ?");
    $find_id = $conn->prepare("SELECT email FROM reg_info WHERE id = ?");
    $teacher = find_user($conn, $teacher_email);
    $teacher_user_id = (int) ($teacher['user_id'] ?? 0) ?: null;
    $insert = $conn->prepare(
        "INSERT INTO reg_info (id, full_name, email, pass, role, must_change_password, managed_by) VALUES (?, ?, ?, ?, 'student', 1, ?)"
    );
    $enrol = $conn->prepare("INSERT IGNORE INTO section_students (section_id, student_email) VALUES (?, ?)");

    $conn->begin_transaction();
    try {
        foreach ($rows as $row) {
            $line = (int) $row['line'];
            $name = trim($row['name']);
            $email = mb_strtolower(trim($row['email']));
            $sid = trim($row['id']);
            $section_name = clean_section_name($row['section']);

            if ($name === '' || mb_strlen($name) > 50) {
                $report['skipped'][$line] = 'Name is required (up to 50 characters).';
                continue;
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100) {
                $report['skipped'][$line] = 'Invalid email address.';
                continue;
            }
            if (mb_strlen($sid) > 50) {
                $report['skipped'][$line] = 'Student ID is longer than 50 characters.';
                continue;
            }
            if (isset($seen[$email])) {
                $report['skipped'][$line] = "Duplicate of line {$seen[$email]}.";
                continue;
            }
            $seen[$email] = $line;

            if ($section_name !== '') {
                if (mb_strlen($section_name) > SECTION_NAME_MAX) {
                    $report['skipped'][$line] = 'Section name is too long.';
                    continue;
                }
                $section_id = ensure_section($conn, $teacher_email, $section_name);
            } elseif ($fallback_section_id !== null) {
                $section_id = $fallback_section_id;
            } else {
                $report['skipped'][$line] = 'No section given.';
                continue;
            }

            $find_user->bind_param("s", $email);
            $find_user->execute();
            $existing = $find_user->get_result()->fetch_assoc();

            if ($existing && $existing['role'] !== 'student') {
                $report['skipped'][$line] = 'This email belongs to a teacher account.';
                continue;
            }

            if (!$existing) {
                $id_value = $sid === '' ? null : $sid;
                if ($id_value !== null) {
                    $find_id->bind_param("s", $id_value);
                    $find_id->execute();
                    if ($find_id->get_result()->num_rows > 0) {
                        $report['skipped'][$line] = "Student ID $sid is already used by another account.";
                        continue;
                    }
                }
                $insert->bind_param("ssssi", $id_value, $name, $email, $hash, $teacher_user_id);
                $insert->execute();
                $report['created']++;
            }

            $enrol->bind_param("is", $section_id, $email);
            $enrol->execute();
            if ($enrol->affected_rows === 1) {
                $report['enrolled']++;
            } else {
                $report['already']++;
            }
        }
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    } finally {
        $find_user->close();
        $find_id->close();
        $insert->close();
        $enrol->close();
    }

    ksort($report['skipped']);
    return $report;
}

// ---- Quiz visibility -------------------------------------------------------------

/**
 * SQL condition: quiz alias $q is visible to the student bound to the single
 * "?" placeholder. Only the quiz teacher's own students (in one of their
 * sections) can see it, and only if it is assigned to them: to all students,
 * to one of their sections, or to them personally. A quiz with no
 * assignments is open to all of the teacher's students.
 * (Dates are not checked here; see student_window().)
 */
function quiz_visible_sql(string $q = 'q'): string
{
    return "EXISTS (SELECT 1 FROM section_students ssv
                    JOIN sections sv ON sv.id = ssv.section_id
                    WHERE ssv.student_email = ? AND sv.teacher_email = $q.teacher_email
                      AND (NOT EXISTS (SELECT 1 FROM quiz_assignments qa0 WHERE qa0.quiz_id = $q.id)
                           OR EXISTS (SELECT 1 FROM quiz_assignments qa1
                                      WHERE qa1.quiz_id = $q.id AND (
                                          qa1.target_type = 'all'
                                          OR (qa1.target_type = 'student' AND qa1.student_email = ssv.student_email)
                                          OR (qa1.target_type = 'section' AND EXISTS (
                                              SELECT 1 FROM section_students ss2
                                              WHERE ss2.section_id = qa1.section_id AND ss2.student_email = ssv.student_email))))))";
}

function quiz_visible_to_student(mysqli $conn, int $quiz_id, string $student_email): bool
{
    $stmt = $conn->prepare("SELECT 1 FROM quizzes q WHERE q.id = ? AND " . quiz_visible_sql('q'));
    $stmt->bind_param("is", $quiz_id, $student_email);
    $stmt->execute();
    $ok = $stmt->get_result()->num_rows === 1;
    $stmt->close();
    return $ok;
}

/** @return int[] section ids a quiz is assigned to */
function quiz_section_ids(mysqli $conn, int $quiz_id): array
{
    $stmt = $conn->prepare("SELECT section_id FROM quiz_assignments WHERE quiz_id = ? AND target_type = 'section'");
    $stmt->bind_param("i", $quiz_id);
    $stmt->execute();
    $ids = array_map('intval', array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'section_id'));
    $stmt->close();
    return $ids;
}

/**
 * Replace a quiz's section assignments with the given sections (the
 * teacher's own only). Kept for the quiz settings form and older callers;
 * other assignment types and their windows are managed on the Assignments page.
 */
function set_quiz_sections(mysqli $conn, string $teacher_email, int $quiz_id, array $section_ids): void
{
    $own = array_map('intval', array_column(teacher_sections($conn, $teacher_email), 'id'));
    $section_ids = array_values(array_intersect(array_map('intval', $section_ids), $own));

    $stmt = $conn->prepare("SELECT section_id FROM quiz_assignments WHERE quiz_id = ? AND target_type = 'section'");
    $stmt->bind_param("i", $quiz_id);
    $stmt->execute();
    $current = array_map('intval', array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'section_id'));
    $stmt->close();

    $del = $conn->prepare("DELETE FROM quiz_assignments WHERE quiz_id = ? AND target_type = 'section' AND section_id = ?");
    foreach (array_diff($current, $section_ids) as $sid) {
        $del->bind_param("ii", $quiz_id, $sid);
        $del->execute();
    }
    $del->close();

    $ins = $conn->prepare("INSERT IGNORE INTO quiz_assignments (quiz_id, target_type, section_id) VALUES (?, 'section', ?)");
    foreach (array_diff($section_ids, $current) as $sid) {
        $ins->bind_param("ii", $quiz_id, $sid);
        $ins->execute();
    }
    $ins->close();
}
