# Code Review: LADDER

Review of the app (formerly "Quiz App") through the enhancement phases 0-7: plain PHP 8 + MySQL,
no framework. It covers correctness and security problems that were found and fixed, the
architecture the work keeps to, new features, test coverage, and the issues that are still open.

## Architecture (kept as is)

The app stays deliberately simple: one PHP file per page, with the logic at the top of the file and
the HTML below it. Shared logic lives in `includes/`. The review kept that shape and only made the
existing seams clearer:

| Layer | Files | Responsibility |
|---|---|---|
| Config | `db/config.php` (new) | Loads `.env`, `env_value()`, timezone, error display |
| Connection | `db/conn.php` | Opens `$conn`, then runs the schema |
| Schema | `db/schema.php`, `db/migrate.php`, `db/migrations/` | Versioned, idempotent migrations recorded in `schema_migrations` |
| Bootstrap | `includes/bootstrap.php` | Every page's first include: config, error handler, DB, helpers, hardened session |
| Domain logic | `includes/helpers.php`, `quiz_helpers.php`, `class_helpers.php` | Guards, CSRF, sessions, throttling, attempts, snapshots, submit, re-grade, sections, accounts, visibility |
| | `grading.php`, `question_bank.php`, `question_import.php` | Question types and pure graders; bank, catalogue and question form; Markdown importer |
| | `assignments.php`, `notifications.php`, `practice.php`, `analytics.php` | Assignment windows and statuses; inbox and email queue; practice rules and bookmarks; analytics and item analysis |
| Presentation helpers | `includes/ui.php`, `dashboards/partials/`, `assets/` | Icons, toasts, question rendering, SVG charts; sidebars and shared question form; vanilla JS |
| Pages | `auth/`, `dashboards/` | Request handling and HTML |
| JSON endpoints | `save_answer.php`, `begin_quiz.php`, `leave_quiz.php`, `check_answer.php` | Autosave, exam start (returns the questions), lock-mode submit, practice feedback |
| CLI | `db/seed_demo.php`, `db/set_password.php`, `db/send_notifications.php` | Demo data, account recovery, email queue |
| Tests | `tests/` (new) | Dependency-free runner with unit, integration and HTTP suites |

Pages call shared helpers rather than repeating logic. For example, every role-protected page goes
through `require_role()`, which now also enforces CSRF protection and the forced first-login
password change. So these rules live in one place and no page can forget them.

## Findings and fixes

Severity: **High** means exploitable or data-corrupting, **Medium** means a real bug with limited
impact, **Low** means hardening or quality.

| # | Sev | Where | Problem | Fix |
|---|---|---|---|---|
| 1 | High | `db/conn.php` | On Windows, the `username` config key resolved to the OS `USERNAME`, so the app connected as the wrong MySQL user and every page returned 503. | `.env` loaded with `Dotenv::createMutable()` so it overrides inherited variables (moved into `db/config.php`). |
| 2 | High | all forms | No CSRF protection: another site could make a logged-in teacher delete quizzes or change grades. | `csrf_token()` / `csrf_field()` / `verify_csrf()` in `helpers.php`. `require_role()` checks every POST. All 16 dashboard forms and the JSON endpoints send the token. A bad token gets HTTP 403. |
| 3 | High | `quiz_helpers.php` `finalize_attempt()` | Not atomic: a double submit (back button, autosubmit racing a manual submit) inserted duplicate answer rows and graded the attempt twice. | Runs in one transaction and first claims the attempt (`UPDATE … WHERE status='in_progress'`). Only the call that wins grades it. Returns `bool`. |
| 4 | High | `auth/otp_verification.php` | A 4-digit OTP with unlimited guesses could be brute-forced within its 2-minute life. | 5 attempts per OTP, then the OTP is burned. Generated with `random_int()` instead of `rand()`. |
| 5 | High | `auth/password_change.php` | A reset accepted any password, including an empty one. | Same 8–16 character rule as the rest of the app. |
| 6 | Medium | `auth/login.php` | No session id regeneration on login (session fixation). | `login_user()` calls `session_regenerate_id(true)`. |
| 7 | Medium | `auth/registration.php` | `id` is `UNIQUE` but optional, and a blank ID was stored as `''`, so the second person to leave it blank could not register. A duplicate email showed a raw PHP fatal error. | Blank ID is stored as `NULL`. Duplicate key (1062) shows a friendly message. Server-side validation of role, email, name length and password. |
| 8 | Medium | `export_results.php`, `student_profile.php` | CSV formula injection: a student named `=HYPERLINK(...)` runs as a formula when the teacher opens the export in Excel. | `csv_safe()` prefixes `= + - @` values with a quote. |
| 9 | Medium | `db/seed_demo.php` | `bind_param()` was given constants (fatal on PHP 8), and the outer transaction conflicted with the new transactional grading. | Constants copied to variables. Per-attempt transactions. |
| 10 | Low | `settings.php`, `registration.php` | Names over 50 characters overflow `VARCHAR(50)` (an exception in strict mode). | Length validated. |
| 11 | Low | `quiz_questions.php` | Points above 999.99 overflow `DECIMAL(5,2)`. A missing `type` field raised a warning. | Clamped to 999.99. Defaults added. |
| 12 | Low | `quiz_helpers.php` | Short-answer matching used `strtolower()`, so case-insensitive matching failed for non-ASCII answers. | `normalize_short_answer()` uses `mb_strtolower()` and a Unicode whitespace collapse. |
| 13 | Low | `forget_password.php` | Mail errors were echoed to visitors unescaped, and `.env` was loaded twice. | Errors are logged and a generic message shown. Config comes from `env_value()`. |
| 14 | Low | `index.php` | Sent HTML before calling `header()`; this only worked because of output buffering. | Plain redirect. |
| 15 | Low | mobile CSS | Some inputs were 15px, which makes iOS Safari zoom on focus. | Every form control is at least 16px at phone widths. |

## Phase 1 (security and integrity) findings and fixes

| # | Sev | Where | Problem | Fix |
|---|---|---|---|---|
| 16 | High | `quiz_visible_sql()` | A quiz with no sections was visible to every student in the system, including other teachers' students. | Visible only to students in one of the quiz teacher's sections. |
| 17 | High | `view_attempt.php` | Correct answers were shown right after submitting, while classmates (or the same student, on another attempt) could still take the quiz. | Per-quiz results release (immediate / when closed / manual) and an option to hide correct answers. New quizzes default to "when closed". |
| 18 | High | grading and reviews | Results read the live questions: editing points or a key changed past results, and deleting a question deleted students' answers. | Snapshots in `attempt_questions`; grading, reviews and analysis read them; soft delete for answered questions; teacher scores clamped to the snapshot points. |
| 19 | Medium | `take_quiz.php` | Two quick Start requests could create two attempts or exceed the attempt limit. | `start_or_resume_attempt()` locks the quiz row in a transaction; unique key on `(quiz_id, student_email, attempt_number)`. |
| 20 | Medium | `auth/*` | No login throttling, no CSRF on auth forms, logout by GET, account enumeration on reset, 4-digit plain-text OTP with a session-held try counter. | See the Security section of the README. |
| 21 | Medium | sessions | A password change or teacher reset left old sessions signed in; no idle timeout; no cookie flags or anti-framing headers. | `auth_version` check on every request, idle timeout, HttpOnly/SameSite/Secure cookies, security headers. |
| 22 | Medium | schema | `reg_info` had no primary key and no foreign keys pointed at accounts. | `user_id` primary key; foreign keys with `ON UPDATE CASCADE`; orphaned emails get a disabled placeholder account so no history is lost. |
| 23 | Low | `grade_attempt.php` | Grade saves were not in a transaction, and a refresh re-posted them. | `save_teacher_grades()` in one transaction, then a redirect. |
| 24 | Low | `take_quiz.php` | The countdown used the device clock. | Corrected by the server/device clock offset (the server still enforces the deadline). |

## Phases 3-7 findings and fixes

| # | Sev | Where | Problem | Fix |
|---|---|---|---|---|
| 25 | Medium | migration 003 | The snapshot backfill used app helpers that later read columns added in migration 004, so a database upgrading from Phase 0/1 straight to Phase 3 would crash. | Migrations are self-contained (own SQL only); rule documented. |
| 26 | Medium | `regrade_quiz()` | Re-grading kept the snapshot's old decoded rules and silently ignored the corrected key (found by a test). | Old rules dropped before decoding the new key; test covers it. |
| 27 | Medium | `quiz_view_data()` | True/false and multiple-select options were not sent to the quiz page, so those questions showed no choices. | Options passed for every option type; the HTTP test now reads options from the page. |
| 28 | Low | `normalize_answer_text()` | "Ignore punctuation" turned "it's" into "it s", so "its" did not match. | Apostrophes and quotes are removed, other punctuation becomes a space. |
| 29 | Low | analytics | `GROUP BY name` resolved to `subjects.name` instead of the alias, merging every topic into one group. | Alias renamed; covered by tests. |
| 30 | Low | `start_or_resume_attempt()` | Practice attempts defaulted to timed. | Untimed unless the student asks. |
| 31 | Low | `db/seed_demo.php` | `--reset` failed under the new foreign keys and seeded questions had no owner, so they could not be edited. | Content deleted before accounts; questions owned by the demo teacher. |
| 32 | Low | forms | Several labels were not linked to their fields (quiz settings, read-only settings fields, sign-up role). | Labels linked; role is a labelled radio group. |
| 33 | Low | UI | Sortable headers were 19px tap targets on phones; a one-point "trend" chart; chart labels unreadable when scaled down. | 36px targets on touch screens; trends need two results; larger labels on phones. |

## New features

### Question bank, question types and grading engine (Phase 3)

Bank questions are `questions` rows with no quiz, owned by a teacher, copied into quizzes with
`source_question_id`. Seven types with rules in `answer_config` (accepted answers, case,
punctuation, numeric tolerance, partial credit). Graders in `includes/grading.php` are pure
functions; legacy short answers keep their original rule. Re-grading previews changes, never touches
manual scores and is audited in `grade_audit`, as are manual score changes.

### Assignments and notifications (Phase 4)

`quiz_assignments` (replacing `quiz_sections`) targets all students, sections or students with
open/due/late-until windows; attempts store the deadline in force (`due_at`). Notifications use a
per-user dedupe key; email is opt-in and queued.

### Practice mode and bookmarks (Phase 5)

`quiz_attempts.is_practice`; practice-only quizzes or practice after answers are released;
instant feedback endpoint refuses official attempts; practice excluded from every official figure.
Bookmarks point at attempt snapshots.

### Analytics and reports (Phase 6)

Student trend, subject/topic performance and completion (released, official, graded attempts
only); class analytics; item analysis (difficulty, upper/lower 27% discrimination, option
choices); server-side SVG charts; CSV exports; print stylesheet for PDF reports.


### Exam mode (per quiz, on by default)

Teachers turn it on or off with **Lock browser** in the quiz settings (`quizzes.lock_on_leave`).

* **Start gate.** The quiz page opens behind a full-screen overlay with the questions hidden.
  Pressing **Start quiz in full screen** enters full screen (browsers only allow this from a click)
  and calls `begin_quiz.php`, which starts the countdown once (`entered_at`). Time spent reading the
  rules does not count.
* **Leaving submits.** Hiding the page (switching tab or app, minimising, locking the screen), leaving
  it (closing, reloading, navigating away), or switching apps for more than 2 seconds (window blur)
  sends a beacon to `leave_quiz.php`, which grades the attempt with reason `left_page`.
* **Full screen.** Leaving full screen shows a 5-second "Return to full screen" warning, then submits
  with reason `exit_fullscreen`.
* **Blocked actions.** Copy, cut, paste, right-click, drag and drop, selecting question text,
  printing, Ctrl/Cmd+C/X/V/P/S/U, F12 and developer-tools shortcuts. PrintScreen blanks the page and
  clears the clipboard.
* **End of quiz.** Submitting, or any auto-submit, exits full screen.
* **Records.** `quiz_attempts.submit_reason` (`manual`, `time_up`, `left_page`, `exit_fullscreen`)
  is shown to the teacher on the grading page and to the student on their attempt page.
* **No silent restarts.** A new attempt starts only from an explicit **Start** click (`start=1`),
  so reloading after an auto-submit cannot open a fresh attempt.

### Each teacher administers their own classes

There is no separate admin role. The system supports any number of teachers, and **each teacher is
the administrator of their own environment**: their sections, their students, their quizzes and
their evaluations. Teachers never see or change each other's data. Every query and every action is
scoped to the logged-in teacher; forged requests for another teacher's section, student, quiz or
attempt are refused (covered by tests).

"My students" means students in one of my sections, or who submitted one of my quizzes. One
student account can belong to several teachers (the same student in two courses); each teacher only
sees their own sections and results for that student.

* **Sections** (`sections`, `section_students`): a teacher creates, renames and deletes their own
  sections and adds or removes students. Two teachers can each have a "Section A"; they are
  separate. Deleting a section keeps the student accounts and their results.
* **Registering students** (`teachersPages/classes.php`): upload a CSV (`name, email, id, section`,
  header optional, any column order, Excel BOM and semicolons accepted) or add one student by hand.
  New emails become student accounts with a default password. Existing students are only enrolled
  and keep their password. Bad rows are skipped with their line number and reason; good rows are
  imported in one transaction.
* **Managing students** (`teachersPages/student_profile.php`): for their own students, a teacher
  can edit name, email and ID, change which of *their* sections the student is in (other teachers'
  enrolments are untouched), reset the password, or remove the student. Removing deletes the
  student's results on this teacher's quizzes; the account is deleted only if no other teacher has
  the student. An email change is carried through to attempts and enrolments in one transaction.
* **Quiz audiences** (`quiz_sections`): a quiz can be assigned to the teacher's own sections. Students outside those
  sections neither see it nor can start it by URL. A quiz with no sections stays open to everyone,
  so existing quizzes keep working.
* **Default passwords**: `reg_info.must_change_password` sends the user to `auth/first_login.php`
  straight after login, and every protected page redirects there until they choose a password that
  differs from the default. A blank password field generates a readable random one, shown once.
* **Registration**: teachers and students can sign up as before; a new teacher starts with an empty
  environment. `php db/set_password.php` recovers a locked-out teacher account from the command line.

### Questions with AI (Markdown import)

Teachers build a prompt on the Questions page (topic, level, difficulty, language, number of MCQ and
short-answer questions, points), paste it into any AI chat, and upload or paste the Markdown reply.
No API key or outbound request from the server is needed.

* `includes/question_import.php` holds the parser, the importer and the prompt template. The page's
  script fills the same template (passed in with `json_encode`), so the prompt shown and the
  server's `build_question_prompt()` cannot drift apart; a unit test checks that the example inside
  the prompt is itself importable.
* `parse_question_markdown()` is a pure function: forgiving about AI formatting quirks, strict about
  anything that would grade wrongly (an MCQ needs at least 2 options and exactly one correct one).
  Errors name the question and line.
* Import is all or nothing, in one transaction, appended after existing questions, followed by a
  redirect so a refresh cannot import twice. Preview saves nothing. Uploads are limited to 512 KB,
  `.md`/`.markdown`/`.txt`, valid UTF-8; the quiz ownership check runs before any action, so a
  teacher cannot import into another teacher's quiz.

## Tests

Run `php tests/run.php` (or `--no-http` to skip the web-server tests, or `--filter=word`). The suite
has 186 tests, all passing (5 unit files, 9 integration files, 9 HTTP files). Besides the areas
below, they cover the graders and importer for every type, the bank and question form, snapshots
and history, re-grading and audit, assignments and statuses, notifications, practice and
bookmarks, analytics and item analysis, and the security flows (headers, CSRF on auth forms,
lock-out, reset codes, sessions ended by a password change):

* **Unit** (`tests/unit`): escaping, score formatting, availability windows, deadlines, CSRF, CSV
  safety, short-answer normalisation, role routing, password rules, the password generator, CSV
  parsing (header order, BOM, semicolons, missing columns), Markdown question parsing (AI
  variations, every rejection case, line numbers) and the prompt builder.
* **Integration** (`tests/integration`): runs against an isolated `<db_name>_test` database that is
  wiped before each test. Covers answer sanitising, grading, idempotent submit, submit reasons,
  submitting when the student leaves, draft handling, CSV import (new, existing, invalid and
  duplicate rows, fallback section), shared section management, password resets, email changes
  carried through, isolation between teachers, removing shared and unshared students,
  section-based quiz visibility, and importing questions (order, options, answers, grading).
* **HTTP** (`tests/http`): end-to-end through Apache. Covers registration edge cases, login, a CSRF
  rejection, authoring, publishing, taking, autosaving, double-submitting, grading with clamping, CSV
  export, role isolation, teacher isolation (another teacher cannot see or change my quizzes,
  results, sections or students, even with forged requests), lock mode on and off, a real CSV
  upload, first login, a section-limited quiz, editing, resetting and removing students, and the
  AI question import (preview, a broken file, a real `.md` upload, a refused file type, another
  teacher's quiz). These tests use
  `@http-test.local` accounts and `HTTP Test` sections and delete them afterwards. They never change
  real accounts; the test run compares real accounts before and after.

Browser checks (desktop, 375px and 320px) found no horizontal overflow, no form control under 16px
on phones, and every touch target at least 36px tall. These pages were covered: auth, student,
teacher (Classes, Students, quizzes, questions with the AI panel, results), the exam gate, and
first login.

## Known limitations and follow-ups

1. **A shared student's name, email and ID are one account.** If two teachers teach the same
   student, either can correct their name or email and the change shows for both. Sections and
   results stay separate per teacher.

2. **Screenshots cannot be fully blocked by a web page.** Phone hardware buttons, OS capture tools
   that don't take focus, and a second camera all work. Exam mode deters and records these, but
   cannot prevent them.
3. **iPhone Safari has no Fullscreen API** for pages. The gate still works and every other lock
   applies.
4. ~~**The questions are in the HTML behind the gate.**~~ Resolved: a locked quiz renders no
   questions until the student passes the gate; `begin_quiz.php` returns them with the countdown.
5. ~~**No login rate limiting.**~~ Resolved: `auth_throttle` limits failed logins per account and
   per address, sign-ups and reset-code requests.
6. ~~**The forgot-password page reveals whether an email is registered.**~~ Resolved: one neutral
   answer; reset codes are 6 digits, hashed, 10 minutes, 5 tries counted in the database.
7. ~~**Editing or deleting questions on a quiz that already has attempts** deletes answers.~~
   Resolved: attempts keep a snapshot of their questions (`attempt_questions`); deleting an
   answered question hides it (`questions.deleted_at`).
8. ~~**The schema check runs on every request.**~~ Resolved: versioned migrations with a
   `schema_migrations` table (`db/migrate.php`); a normal request does one lookup.
9. **Line endings are mixed.** Some edited files are LF in a CRLF repository. Git normalises them on
   commit; a `.gitattributes` file would make that explicit.
10. **Notification emails are sent at the end of a request.** Without `fastcgi_finish_request()`
   (Apache mod_php) a few seconds of SMTP can delay that response for users who opted in; run
   `db/send_notifications.php` from cron to keep pages fast.
11. **AI-assisted grading is not built.** Essays and unkeyed short answers are graded by hand; the
   grading engine is the place to add a suggestion step, with the teacher keeping the final grade.
12. **Fonts use the system stack** (Inter if installed); no web font is downloaded.
13. **`quiz_sections` was dropped** after its rows moved to `quiz_assignments` (migration 005);
   restore from a backup if an older version of the code must run against the database again.
