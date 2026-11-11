# LADDER

A role-based assessment and learning platform for teachers and students,
built with plain PHP + MySQL and vanilla HTML, CSS and JavaScript (no
framework, no build step).

Teachers build quizzes from a reusable question bank, assign them to classes
or individual students with deadlines, grade with flexible rules, and follow
class performance. Students take quizzes in a focused exam screen, practise
with instant feedback, review their answers, bookmark hard questions and
track their progress.

## Overview

There are two account types, **Teacher** and **Student**. Each role has its
own dashboard and sidebar and can only open pages meant for it.

**Each teacher has a private workspace and is its administrator.** Teachers
create their own class sections, register their own students and run their
own quizzes. They never see or change another teacher's classes, students,
quizzes, questions or results; every query and action is scoped to the
signed-in teacher (forged requests are refused and covered by tests).

### Teachers can

- **Manage classes**: create sections, import students from a CSV or add
  them one by one (new accounts get a default password they must change at
  first login), edit, reset passwords or remove students.
- **Build questions** in a **Question Bank**, organised by subject, topic,
  type and difficulty, with search, filters and paging. Questions are copied
  into quizzes, so editing the bank never changes a quiz already using a
  question (and vice versa). Questions can also be written straight into a
  quiz, saved back to the bank, or generated with AI (see
  [Questions with AI](#questions-with-ai)).
- **Use seven question types**: multiple choice, true/false, multiple select,
  short answer, numerical, fill in the blanks and essay, each with optional
  difficulty, topic and an explanation shown to students with the answer.
- **Set quizzes up**: time limit, attempt limit, shuffled questions and
  options, open/close times, exam mode, subject, official or practice-only
  mode, and when results are shown.
- **Assign quizzes** to all their students, to sections, or to selected
  students, each with its own opening date, due date and optional
  late-submission window, and follow completion (not started, in progress,
  completed, completed late, overdue).
- **Grade** with automatic rules and by hand, give feedback, override any
  score, and **re-grade** past attempts after fixing a key (preview first).
  Every score change is recorded.
- **Analyse** class performance (distribution, per quiz, section and topic,
  per student) and each quiz's questions (difficulty, discrimination,
  options chosen); export CSVs and print or save PDF reports.

### Students can

- See the quizzes assigned to them with due dates, late windows and status;
  get reminders for deadlines within 24 hours.
- Take quizzes in a distraction-free screen with a timer, a question palette
  and answers saved as they go; resume after a dropped connection.
- Review released results: their answers, the correct answers (if shown),
  explanations, marks and teacher feedback; print a result report.
- **Practise** with unlimited attempts and instant feedback per question.
  Practice never counts towards grades.
- **Bookmark** questions to revisit, with a personal note.
- Follow **their progress**: score trend, subject-wise performance,
  strongest and weakest topics, completion; print a progress report.
- Receive in-app **notifications** (optionally by email).

## Exam mode

Each quiz has an exam-mode setting (on by default). When it is on:

- The quiz opens behind a start screen. **No question is sent to the
  browser until the student presses Start**, which enters full screen and
  starts the countdown.
- Switching tabs or apps, minimising, locking the screen, reloading, closing
  the page or leaving full screen (after a 5-second warning) **submits the
  quiz automatically**. The reason is recorded and shown to the teacher.
- Copy, paste, right-click, text selection, printing and the PrintScreen key
  are blocked.

A web page cannot fully stop screenshots (phone buttons, external cameras),
and iPhone Safari has no full-screen support for web pages; all the other
locks still apply there. Practice attempts never use exam mode.

## Question types and grading

| Type | Answer | Automatic grading |
|---|---|---|
| Multiple choice | one option | the correct option |
| True / False | True or False | the correct value |
| Multiple select | several options | all-or-nothing, or partial credit (each wrong choice cancels a right one) |
| Short answer | text | any of several accepted answers; case-sensitive or not; punctuation ignored or not; spacing always ignored. No accepted answer = graded by hand |
| Numerical | a number (`3.5`, `3,5`, `1e-3`) | within an absolute or percentage tolerance |
| Fill in the blanks | one text per `___` | accepted answers per blank; partial credit per blank or all-or-nothing |
| Essay | long text | always graded by hand |

- Unanswered questions score zero; anything needing a teacher appears in the
  **Pending Evaluation** queue and in the teacher's notifications.
- **History is preserved.** When an attempt starts, its questions, options
  (in the order the student sees them), points, grading rules, topic and
  explanation are copied into `attempt_questions`. Grading, reviews and
  analytics read that copy, so editing or deleting a question later never
  changes a past result. Deleting a question students have answered only
  hides it from the quiz.
- **Re-grade with the current key** (quiz Results page) applies a corrected
  key, points or rules to submitted attempts: it previews every change
  first, never touches scores a teacher entered by hand, and records each
  change in the grade history (`grade_audit`), as are manual score changes.
- **Results release**: students see scores, feedback and correct answers
  right after submitting, when the quiz closes (default for new quizzes), or
  only when the teacher clicks **Release results now**; correct answers can
  be hidden altogether. Teachers always see everything.

## Assignments and deadlines

A quiz with no assignments is open to all of the teacher's students (those
in one of their sections), within the quiz's own open/close times. On the
**Assign** page a teacher can instead target all students, sections or
individual students, each with optional open, due and "late submissions
until" dates. When a student matches several assignments, the most specific
one applies (student, then section, then all).

The server decides availability for every request. Each attempt records the
deadline in force when it started, so "submitted late" is fixed history even
if the deadline is moved later.

## Practice mode and bookmarks

- A quiz can be **practice only** (unlimited attempts, never graded), or an
  official quiz can **allow practice** once its results and answers are
  released, so practice can never leak answers to classmates still taking it.
- Practice attempts have no exam lock, use the time limit only if the
  student chooses, and offer **Check answer** for instant feedback (graded
  on the server from the attempt snapshot; refused for official attempts).
- Practice attempts are excluded from grades, results, exports, dashboards,
  completion, analytics and notifications.
- Students bookmark questions from any review; the Bookmarks page shows the
  question as it was in that attempt, their answer, the correct answer when
  they may see it, and a personal note.

## Notifications

In-app notifications (the bell in the sidebar, with an unread count) for:
quiz assigned, deadline within 24 hours, results published, new teacher
feedback, and answers waiting for evaluation (teachers). The same event is
never notified twice. Users can turn on email copies in **Settings**; emails
are queued and a few are sent after each request, or run
`php db/send_notifications.php` from a cron job.

## Analytics and reports

- **Students: My Progress**: score trend, subject-wise performance,
  strongest and weakest topics (60% line, at least two answers), completion.
  Only official attempts whose results are released count.
- **Teachers: Analytics**: average, median and pass rate, score
  distribution, averages per quiz, section and topic, and a per-student
  table, filtered by section and quiz.
- **Question analysis** on each quiz's Results page: average (difficulty),
  discrimination (top 27% of attempts minus bottom 27%; above 0.3 separates
  strong and weak students well, near 0 or negative suggests confusing
  wording or a wrong key), flags, and how often each option was chosen.
- **CSV exports**: quiz results, question analysis, class analytics,
  rosters. Cells are protected against spreadsheet formula injection.
- **PDF reports**: **Print / Save as PDF** on result reviews, progress, class
  analytics and quiz results uses a print stylesheet (no extra software).

## Security

- Passwords are hashed with `password_hash()`; 6-64 characters.
- Every form, including login, sign-up and password reset, carries a CSRF
  token; logging out is a POST; all output is escaped.
- Failed logins are limited per account (5 per 15 minutes) and per address;
  sign-ups and reset-code requests are limited too (`auth_throttle`).
- Password reset uses a 6-digit code, stored only as a hash, valid for 10
  minutes and 5 tries. The reset page answers the same way whether or not an
  email is registered.
- Changing or resetting a password signs the account out on every other
  device (`reg_info.auth_version`). Sessions also end after
  `SESSION_IDLE_MINUTES` without activity (default 240).
- Session cookies are HttpOnly, SameSite=Lax and Secure on HTTPS; pages send
  `X-Frame-Options: DENY`, `nosniff` and a same-origin referrer policy.
- The server is the authority for availability, time limits, attempt limits
  (the quiz row is locked, so a double click cannot start two attempts),
  submissions, grading and practice feedback.

## Interface

Blue, white and near-black palette (navy `#142B4A`, blue `#2563EB`, page
`#F5F7FA`, text `#172033`), square corners everywhere, restrained shadows and
150-200 ms transitions (none during exams; reduced-motion respected). A fixed
navy sidebar collapses behind a menu button on tablets and phones. Tables get
search, sorting and paging; confirmations use an accessible dialog; charts
are drawn as inline SVG with text alternatives. Pages are checked at 1280px
and 375px: no horizontal scrolling, labelled controls, form controls at least
16px on phones, visible focus rings.

## Tech stack

- PHP 8.1+ (mysqli, prepared statements throughout)
- MySQL / MariaDB (InnoDB, foreign keys)
- Plain HTML, CSS and vanilla JavaScript
- [vlucas/phpdotenv](https://github.com/vlucas/phpdotenv) for configuration,
  [PHPMailer](https://github.com/PHPMailer/PHPMailer) for email

## Project structure

```
index.php                Landing page
auth/                    Sign in, sign up, logout, first login, password reset
assets/
  app.js                 Sidebar menu, toasts, confirm dialog, table search/sort/paging, print
  question_form.js       Question editor: fields per question type
dashboards/
  teacherDashboard.php   Teacher home
  studentDashboard.php   Student home: stats, recent scores, upcoming deadlines
  notifications.php      Notification center (both roles)
  settings.php           Profile, password, email notifications
  appStyle.css           Design tokens and components; navStyle.css: sidebar
  partials/              Sidebars, shared question form
  teachersPages/
    setQuiz.php            Quizzes and their settings
    quiz_questions.php     A quiz's questions: editor, bank picker, AI import
    question_bank.php      Question bank, subjects and topics
    assignments.php        Assign a quiz, track completion
    results.php            Results, pending queue, re-grade, question analysis
    grade_attempt.php      Grade one attempt, grade history
    analytics.php          Class analytics
    classes.php            Sections and student import
    student_profile.php    My students
    export_results.php     Quiz results CSV
  studentPages/
    quizzes.php            Assigned quizzes
    take_quiz.php          Exam screen (official and practice)
    save_answer.php, begin_quiz.php, leave_quiz.php, check_answer.php   JSON endpoints
    view_attempt.php       Result review and bookmarks
    results.php            My results
    practice.php, bookmarks.php, progress.php
db/
  config.php, conn.php   Configuration and connection
  schema.php, migrate.php, migrations/   Versioned schema migrations
  seed_demo.php          Demo data (CLI)
  set_password.php       Set any account's password (CLI)
  send_notifications.php Send queued notification emails (CLI / cron)
includes/
  bootstrap.php          First include of every page
  helpers.php            Guards, CSRF, sessions, throttling, flash messages
  quiz_helpers.php       Attempts, snapshots, submit, results release, re-grade
  grading.php            Question types and graders
  question_bank.php      Bank, subjects/topics, question form and saving
  question_import.php    Markdown/AI importer
  class_helpers.php      Sections, students, CSV import, quiz visibility
  assignments.php        Assignments, windows, statuses
  notifications.php      Notifications and email queue
  practice.php           Practice rules, instant feedback, bookmarks
  analytics.php          Student and class analytics, item analysis, CSV
  ui.php                 Icons, toasts, question rendering, SVG charts
tests/                   Test suite (see Testing)
docs/CODE_REVIEW.md      Review findings, fixes, known limitations
```

## Database

The schema is managed by versioned migrations in `db/migrations/`. Applied
versions are recorded in `schema_migrations`; a new migration runs
automatically, once, on the first request after it is deployed (under a
MySQL lock). `001_baseline.php` is the original schema, so an existing
database upgrades in place without losing data.

To change the schema, add the next file returning
`function (mysqli $conn): void { ... }`. MySQL cannot roll back DDL, so make
each step safe to re-run (`CREATE TABLE IF NOT EXISTS`, `ensure_column()`,
`ensure_index()`, `ensure_foreign_key()`), keep migrations self-contained
(no app helpers), and never edit one that has shipped.

Main tables: `reg_info` (accounts), `sections`, `section_students`,
`quizzes`, `quiz_assignments`, `questions` (quiz questions and bank
questions), `question_options`, `subjects`, `topics`, `quiz_attempts`,
`attempt_questions` (snapshots), `attempt_answers`, `grade_audit`,
`question_bookmarks`, `notifications`, `auth_throttle`.

Every `*_email` column referencing an account has a foreign key to
`reg_info(email)` with `ON UPDATE CASCADE`, so an email change carries
through. Accounts that still own quizzes, questions or attempts cannot be
deleted by accident (`ON DELETE RESTRICT`).

## Setup

1. Install dependencies: `composer install`
2. Copy `.env.example` to `.env` and fill in the database credentials and,
   optionally, `APP_URL` and an email account for reset codes and
   notification emails
3. Point your web server at the project root; the tables are created on the
   first visit
4. Visit `index.php`, sign up as a teacher, create your sections under
   **Classes** and import your students

## Importing students

On **Classes**, upload a CSV saved from Excel or Google Sheets ("CSV UTF-8").
The header row is optional and the columns can be in any order:

```csv
name,email,id,section
Rafi Ahmed,rafi@example.edu,2022-1-60-001,CSE-101 Section A
Nusrat Khan,nusrat@example.edu,2022-1-60-002,CSE-101 Section B
```

Sections that don't exist yet are created. Rows with problems (bad email, an
ID already used by someone else, a teacher's email, duplicates) are skipped
and listed with their line number; the rest are imported. The default
password is shown once so you can hand it out.

## Questions with AI

The Questions page of every quiz has a **Generate questions with AI** panel.
It works with any AI chat (ChatGPT, Claude, Gemini, …) and needs no API key:

1. **Describe the quiz** (topic, level, difficulty, language, number of
   questions, points); the prompt is rebuilt as you type and can be edited.
2. **Copy prompt** into the AI chat; it replies with Markdown.
3. **Upload** the reply as a `.md` file or paste it. **Preview** shows the
   questions with the answers marked; **Import questions** adds them.

```markdown
## Q1 [mcq] (1 point)
Which pigment gives leaves their green colour?
- [ ] Carotene
- [x] Chlorophyll
- [ ] Xanthophyll
Explanation: Chlorophyll absorbs red and blue light.

## Q2 [short] (2 points)
Which gas do plants release during photosynthesis?
Answer: Oxygen | O2

## Q3 [tf] (1 point)
Water boils at 100 °C at sea level.
Answer: True

## Q4 [multi] (2 points)
Which of these are prime numbers?
- [x] 2
- [x] 3
- [ ] 4

## Q5 [numeric] (2 points)
Acceleration due to gravity on Earth, in m/s²?
Answer: 9.81 ± 0.05

## Q6 [blank] (2 points)
The capital of France is ___ and it lies on the river ___.
Answer: Paris; Seine

## Q7 [essay] (5 points)
Explain how photosynthesis supports life on Earth.
```

`[x]` marks correct options; `|` separates accepted alternatives; `;`
separates blanks; `± 2%` gives a percentage tolerance; `Answer: none` (or no
`Answer:` line) on a short answer means you grade it by hand. Two sample
files can be downloaded from the panel.

The import is all or nothing: if any question has a problem, nothing is saved
and each problem is listed with its line number. Common AI quirks are
accepted (a wrapping code fence, "marks" instead of "points", lettered
options, `**Answer:** B`). AI can be wrong, so check the preview.

## Locked out?

Students who forget their password get a new default one from their teacher
(**Students → open the student → Set default password**). If a teacher
forgets theirs and email isn't configured, set it from the project folder:

```bash
php db/set_password.php teacher@school.edu "NewPass@123"
```

Add `--must-change` to make them choose a new password at next login. This
also lifts a login lock-out and signs the account out everywhere. A lock-out
from too many failed logins otherwise ends by itself after 15 minutes.

## Demo data

To fill a local database with sample accounts, quizzes and attempts (a local
database only, never a live site):

```bash
php db/seed_demo.php
```

Logins: `teacher@demo.test` (teacher) and `student01@demo.test` to
`student12@demo.test` (students, in two sections). Password for all:
`Demo@1234`. `php db/seed_demo.php --reset` removes the demo data.

## Testing

A dependency-free test suite in plain PHP:

```bash
php tests/run.php
```

- `tests/unit`: pure functions (graders, normalisation, importer, helpers).
- `tests/integration`: against an isolated `<db_name>_test` database that is
  created and wiped automatically; real data is never touched.
- `tests/http`: end-to-end flows through the web server, using only
  `@http-test.local` accounts that are removed afterwards. Set
  `TEST_BASE_URL` if the app is not at `http://localhost/Quiz-App`.

Use `--no-http` to skip the web-server tests and `--filter=word` to run
matching tests. The exit code is non-zero when a test fails.

## Deploying

The app runs on shared PHP 8.1+ / MySQL hosting (for example InfinityFree).
**Back up the database before deploying an update.** Upload the project into
the web root with a `.env` based on `.env.example`; pending migrations run on
the first visit.
