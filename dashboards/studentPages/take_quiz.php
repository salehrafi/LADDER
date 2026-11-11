<?php
require_once __DIR__ . '/../../includes/bootstrap.php';

require_role('student', '../../auth/login.php');

$student_email = current_user_email();
$quiz_id = (int) ($_GET['quiz_id'] ?? $_POST['quiz_id'] ?? 0);

$stmt = $conn->prepare("SELECT * FROM quizzes WHERE id = ?");
$stmt->bind_param("i", $quiz_id);
$stmt->execute();
$quiz = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$quiz) {
    header("Location: quizzes.php");
    exit;
}

// Practice: a practice-only quiz, or ?practice=1 on a quiz open for practice.
// Practice attempts are unlimited, never locked and never count.
$practice = ($quiz['mode'] ?? 'official') === 'practice' || isset($_GET['practice']) || !empty($_POST['practice']);
$practice_q = $practice ? '&practice=1' : '';

// This student's window (assignment dates, falling back to the quiz's own).
$window = student_window($conn, $quiz, $student_email);
$avail = window_availability($quiz, $window);
$locked = !$practice && (int) ($quiz['lock_on_leave'] ?? 1) === 1;

// --- Handle submission ---------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_attempt') {
    $attempt_id = (int) ($_POST['attempt_id'] ?? 0);
    $attempt = load_attempt_for_student($conn, $attempt_id, $student_email);

    if (!$attempt || (int) $attempt['quiz_id'] !== $quiz_id) {
        header("Location: quizzes.php");
        exit;
    }
    $practice = (int) $attempt['is_practice'] === 1;

    // The server decides the deadline, not the browser. A submit that arrives
    // well after the deadline is graded from the last autosaved draft instead.
    $deadline = attempt_deadline_epoch($attempt);
    $late = $deadline !== null && time() > $deadline + QUIZ_SUBMIT_GRACE_SECONDS;

    $answers = $late
        ? load_draft($attempt)
        : sanitize_answers($conn, $attempt, $_POST);

    // The countdown script sends auto=time_up when it submits for the student.
    $reason = ($late || ($_POST["auto"] ?? "") === "time_up") ? "time_up" : "manual";
    finalize_attempt($conn, $attempt, $answers, $reason);

    $query = $late ? "&expired=1" : "";
    header("Location: view_attempt.php?attempt_id=" . $attempt_id . $query);
    exit;
}

// --- Resume an in-progress attempt, or start a new one -------------------
$is_practice = $practice ? 1 : 0;
$stmt = $conn->prepare("SELECT * FROM quiz_attempts WHERE quiz_id = ? AND student_email = ? AND status = 'in_progress' AND is_practice = ? LIMIT 1");
$stmt->bind_param("isi", $quiz_id, $student_email, $is_practice);
$stmt->execute();
$attempt = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$attempt) {
    // A new attempt starts only from an explicit Start click (start=1), so a
    // reload after a lock-mode auto-submit cannot silently open a fresh attempt.
    // Quizzes assigned to sections can only be started by students in those
    // sections (an attempt already under way can still be finished).
    if ($practice) {
        $why = practice_block_reason($conn, $quiz, $student_email);
        if ($why !== null || !isset($_GET['start'])) {
            if ($why !== null) {
                flash('warning', $why);
            }
            redirect('practice.php');
        }
        $started = start_or_resume_attempt($conn, $quiz, $student_email, null, true, isset($_GET['timed']));
        redirect('take_quiz.php?quiz_id=' . $quiz_id . '&practice=1');
    }
    if (!$avail['open'] || !isset($_GET['start']) || !quiz_visible_to_student($conn, $quiz_id, $student_email)) {
        header("Location: quizzes.php");
        exit;
    }

    // Locked and transactional: a double click cannot create two attempts or
    // go past the attempt limit. The questions are snapshotted for this attempt.
    $started = start_or_resume_attempt($conn, $quiz, $student_email, $window['due_at'] ?? null);
    if ($started['error'] !== null) {
        header("Location: quizzes.php");
        exit;
    }

    header("Location: take_quiz.php?quiz_id=" . $quiz_id);
    exit;
}

// If this attempt's time limit already ran out (e.g. the student closed the
// tab before the countdown could auto-submit), close it out now using the
// last autosaved answers.
// In exam mode the clock only starts at the full-screen gate (entered_at), so
// an attempt still waiting at the gate cannot have expired.
$waiting_at_gate = $locked && empty($attempt['entered_at']);
$deadline_epoch = attempt_deadline_epoch($attempt);
if (!$waiting_at_gate && $deadline_epoch !== null && time() >= $deadline_epoch) {
    finalize_attempt($conn, $attempt, load_draft($attempt), 'time_up');
    header("Location: view_attempt.php?attempt_id=" . $attempt['id'] . "&expired=1");
    exit;
}

$attempt_id = (int) $attempt['id'];
$draft = load_draft($attempt);

// Questions and options exactly as snapshotted when the attempt started, in
// the order this student sees them. Correct-answer flags never reach the page.
[$questions_by_id, $options_by_question] = quiz_view_data($conn, $attempt);
$question_order = array_keys($questions_by_id);

$active = 'quizzes';
$nav_base = '../';

// Which questions are already answered (from the autosaved draft).
$answered_ids = [];
foreach ($question_order as $qid) {
    if (isset($draft["q_$qid"]) && $draft["q_$qid"] !== '') {
        $answered_ids[] = $qid;
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../navStyle.css?v=9">
    <link rel="stylesheet" href="../appStyle.css?v=9">
    <title><?php echo e($quiz['title']); ?></title>
</head>

<body class="quiz-page<?php echo $locked ? ' exam-mode exam-gated' : ''; ?>">
    <?php include APP_ROOT . '/dashboards/partials/nav_student.php'; ?>

    <?php if ($locked): ?>
        <!-- Exam mode start gate: questions stay hidden until the student starts. -->
        <div class="exam-gate" id="exam-gate" role="dialog" aria-modal="true" aria-labelledby="exam-gate-title">
            <div class="exam-gate-card">
                <h1 id="exam-gate-title"><?php echo e($quiz['title']); ?></h1>
                <p class="exam-gate-lead">
                    <?php echo $waiting_at_gate ? 'This quiz runs in exam mode.' : 'Your quiz is in progress. Return to full screen to continue.'; ?>
                </p>
                <ul class="exam-rules">
                    <li>The quiz opens in <strong>full screen</strong><?php echo $deadline_epoch && $waiting_at_gate ? ' and the <strong>' . (int) $attempt['time_limit_minutes'] . '-minute countdown</strong> starts' : ''; ?> when you press Start.</li>
                    <li>Switching tabs or apps, minimising, locking your screen, reloading or closing the page <strong>submits your quiz automatically</strong>.</li>
                    <li>Leaving full screen gives you <?php echo 5; ?> seconds to return, then the quiz is submitted.</li>
                    <li>Copy, paste, right-click, printing and screenshots are disabled.</li>
                    <li>When you submit, your browser returns to normal.</li>
                </ul>
                <p class="exam-gate-error" id="exam-gate-error" hidden>Could not start the quiz. Check your connection and try again.</p>
                <button type="button" class="btn btn-block" id="exam-start"><?php echo $waiting_at_gate ? 'Start quiz in full screen' : 'Continue in full screen'; ?></button>
                <a class="exam-gate-back" href="quizzes.php">Not now, back to quizzes</a>
            </div>
        </div>

        <!-- Shown when the student leaves full screen during the exam. -->
        <div class="exam-gate fs-warning" id="fs-warning" role="alertdialog" aria-modal="true" aria-labelledby="fs-warning-title" hidden>
            <div class="exam-gate-card">
                <h2 id="fs-warning-title">You left full screen</h2>
                <p class="exam-gate-lead">Return to full screen within <strong id="fs-count">5</strong> seconds or your quiz will be submitted.</p>
                <button type="button" class="btn btn-block" id="fs-return">Return to full screen</button>
            </div>
        </div>

        <div class="exam-toast" id="exam-toast" role="status" aria-live="polite"></div>
    <?php endif; ?>

    <div class="quiz-bar" id="quiz-bar">
        <div class="quiz-bar-top">
            <div class="quiz-progress">
                <strong id="answered-count"><?php echo count($answered_ids); ?></strong>
                / <span id="total-count"><?php echo count($question_order); ?></span> answered
            </div>
            <div id="quiz-timer" class="quiz-timer" data-mode="<?php echo $deadline_epoch ? 'countdown' : 'stopwatch'; ?>">
                <?php echo $deadline_epoch ? '--:--' : '0:00'; ?>
            </div>
        </div>
        <nav class="q-palette" aria-label="Question navigation">
            <?php foreach ($question_order as $idx => $qid):
                if (!isset($questions_by_id[$qid])) continue;
                $is_answered = in_array($qid, $answered_ids, true);
            ?>
                <a href="#q-<?php echo $qid; ?>" data-qid="<?php echo $qid; ?>" class="<?php echo $is_answered ? 'answered' : ''; ?>" aria-label="Question <?php echo $idx + 1; ?>"><?php echo $idx + 1; ?></a>
            <?php endforeach; ?>
        </nav>
        <div class="quiz-legend" aria-hidden="true"><span class="is-answered">Answered</span><span>Not answered</span></div>
    </div>

    <div class="container" id="main">
        <div class="page-header">
            <div>
                <h1><?php echo e($quiz['title']); ?><?php if ($practice): ?> <span class="badge badge-info">Practice</span><?php endif; ?></h1>
                <?php if ($practice): ?><p class="subtitle">Practice attempt: it does not count towards your grade. Use <strong>Check answer</strong> for instant feedback.</p><?php endif; ?>
                <?php if ($quiz['description']): ?><p class="subtitle"><?php echo e($quiz['description']); ?></p><?php endif; ?>
                <?php if ($deadline_epoch): ?>
                    <p class="subtitle">Time limit: <?php echo (int) $attempt['time_limit_minutes']; ?> minute(s). The quiz submits automatically when time runs out.</p>
                <?php else: ?>
                    <p class="subtitle">No time limit. The timer only shows how long you have been working.</p>
                <?php endif; ?>
                <p class="helptext" id="save-status" aria-live="polite">Your answers are saved as you go.</p>
            </div>
        </div>

        <?php if ($locked): ?>
            <div class="alert alert-warning lock-banner" role="note">
                <strong>Locked quiz.</strong> Stay on this page in full screen until you submit. Switching tabs or apps, minimising, locking your screen,
                leaving full screen, reloading or closing this page will <strong>submit your quiz automatically</strong>.
            </div>
        <?php endif; ?>
        <div id="left-banner" class="alert alert-error" style="display:none;" role="alert">You left the quiz page, so your quiz was submitted.</div>
        <div id="time-up-banner" class="alert alert-info" style="display:none;">Time's up. Submitting your answers now...</div>

        <form method="POST" id="quiz-form"><?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="submit_attempt">
            <input type="hidden" name="quiz_id" value="<?php echo $quiz_id; ?>">
            <input type="hidden" name="attempt_id" value="<?php echo $attempt_id; ?>">
            <?php if ($practice): ?><input type="hidden" name="practice" value="1"><?php endif; ?>
            <input type="hidden" name="auto" id="auto-field" value="">

            <div id="question-list">
                <?php
                // Exam mode: no question reaches the browser before the start gate;
                // begin_quiz.php returns them once the countdown has started.
                if (!$waiting_at_gate) {
                    echo render_quiz_questions($questions_by_id, $options_by_question, $draft, $practice);
                }
                ?>
            </div>

            <div class="submit-row">
                <button type="submit" class="btn btn-block" id="submit-btn">Submit quiz</button>
            </div>
        </form>
    </div>

    <script>
        (function () {
            const ATTEMPT_ID = <?php echo $attempt_id; ?>;
            const CSRF_TOKEN = <?php echo json_encode(csrf_token()); ?>;
            const SAVE_URL = 'save_answer.php';
            const LEAVE_URL = 'leave_quiz.php';
            const LOCKED = <?php echo $locked ? 'true' : 'false'; ?>;
            const RESULT_URL = 'view_attempt.php?attempt_id=' + ATTEMPT_ID + '&left=1';
            const QUESTION_IDS = <?php echo json_encode(array_values(array_intersect($question_order, array_keys($questions_by_id)))); ?>;
            const TOTAL = QUESTION_IDS.length;
            let deadline = <?php echo $deadline_epoch ? ($deadline_epoch * 1000) : 'null'; ?>;
            let startedAt = <?php echo ((int) strtotime($attempt['started_at'])) * 1000; ?>;
            // Server time minus this device's clock, so a wrong device clock cannot shift the countdown.
            const CLOCK_OFFSET = <?php echo time() * 1000; ?> - Date.now();
            const serverNow = () => Date.now() + CLOCK_OFFSET;

            const form = document.getElementById('quiz-form');
            const timerEl = document.getElementById('quiz-timer');
            const statusEl = document.getElementById('save-status');
            const answeredEl = document.getElementById('answered-count');
            const submitBtn = document.getElementById('submit-btn');

            let dirty = false;      // local changes not yet saved
            let saving = false;
            let submitting = false; // set once the student or the timer submits
            let timeUp = false;
            let saveTimer = null;
            let lastSent = null;

            // Answers keyed q_ID: an option id, a list of ids (multiple select),
            // a list of blank texts (fill in the blanks), or text.
            function collectAnswers() {
                const out = {};
                const base = name => name.replace(/\[\]$/, '');
                form.querySelectorAll('input[type="radio"]:checked').forEach(r => { out[r.name] = r.value; });
                form.querySelectorAll('input[type="checkbox"]:checked').forEach(c => {
                    (out[base(c.name)] = out[base(c.name)] || []).push(c.value);
                });
                const blanks = {};
                form.querySelectorAll('input[data-blank]').forEach(i => {
                    (blanks[base(i.name)] = blanks[base(i.name)] || []).push(i.value);
                });
                Object.keys(blanks).forEach(k => {
                    if (blanks[k].some(v => v.trim() !== '')) out[k] = blanks[k];
                });
                form.querySelectorAll('textarea, input.numeric-input').forEach(t => {
                    if (t.value.trim() !== '') out[t.name] = t.value;
                });
                return out;
            }

            function answeredCount(answers) {
                return QUESTION_IDS.filter(qid => answers['q_' + qid] !== undefined).length;
            }

            // Keep the progress counter, palette and option highlights in sync.
            function refreshUI() {
                const answers = collectAnswers();
                answeredEl.textContent = answeredCount(answers);
                QUESTION_IDS.forEach(qid => {
                    const link = document.querySelector('#quiz-bar a[data-qid="' + qid + '"]');
                    if (link) link.classList.toggle('answered', answers['q_' + qid] !== undefined);
                });
                document.querySelectorAll('.option-row').forEach(row => {
                    const input = row.querySelector('input');
                    row.classList.toggle('selected', input.checked);
                });
            }

            function setStatus(text, isError) {
                statusEl.textContent = text;
                statusEl.style.color = isError ? 'var(--error-text)' : '';
            }

            function scheduleSave() {
                dirty = true;
                refreshUI();
                setStatus('Saving...');
                clearTimeout(saveTimer);
                saveTimer = setTimeout(saveNow, 700);
            }

            async function saveNow() {
                if (!dirty || saving || submitting) return;
                saving = true;
                const answers = collectAnswers();
                const payload = JSON.stringify(answers);
                try {
                    const res = await fetch(SAVE_URL, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        credentials: 'same-origin',
                        body: JSON.stringify({ attempt_id: ATTEMPT_ID, csrf_token: CSRF_TOKEN, answers: answers })
                    });
                    const data = await res.json().catch(() => ({ ok: false }));
                    if (res.ok && data.ok) {
                        lastSent = payload;
                        const current = JSON.stringify(collectAnswers());
                        dirty = current !== payload;
                        setStatus(dirty ? 'Saving...' : 'All answers saved at ' + data.saved_at);
                        if (dirty) scheduleSave();
                    } else if (data.error === 'time_up' || data.error === 'attempt_closed') {
                        dirty = false;
                        setStatus('This attempt has closed. Your last saved answers will be graded.', true);
                    } else {
                        setStatus('Could not save. Retrying...', true);
                        clearTimeout(saveTimer);
                        saveTimer = setTimeout(saveNow, 4000);
                    }
                } catch (err) {
                    setStatus('You appear to be offline. Answers will save when the connection returns.', true);
                    clearTimeout(saveTimer);
                    saveTimer = setTimeout(saveNow, 5000);
                } finally {
                    saving = false;
                }
            }

            // Best-effort save when the tab is hidden or closed (e.g. phone switches apps).
            function saveBeacon() {
                if (!dirty || submitting || !navigator.sendBeacon) return;
                const blob = new Blob([JSON.stringify({ attempt_id: ATTEMPT_ID, csrf_token: CSRF_TOKEN, answers: collectAnswers() })], { type: 'application/json' });
                navigator.sendBeacon(SAVE_URL, blob);
            }

            form.addEventListener('change', scheduleSave);

            // Practice: instant feedback per question (the server grades it
            // from the attempt's snapshot; official attempts are refused).
            form.addEventListener('click', async (e) => {
                const btn = e.target.closest('[data-check]');
                if (!btn) return;
                const qid = btn.dataset.check;
                const out = document.getElementById('check-' + qid);
                btn.disabled = true;
                out.textContent = 'Checking...';
                try {
                    const res = await fetch('check_answer.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        credentials: 'same-origin',
                        body: JSON.stringify({ attempt_id: ATTEMPT_ID, csrf_token: CSRF_TOKEN, question_id: Number(qid), answer: collectAnswers()['q_' + qid] ?? null })
                    });
                    const data = await res.json();
                    if (!res.ok || !data.ok) throw new Error(data.error || 'failed');
                    out.className = 'check-result ' + (data.correct === null ? 'is-pending' : (data.correct ? 'is-right' : 'is-wrong'));
                    const verdict = data.correct === null ? 'This answer is graded by your teacher, not automatically.'
                        : (data.correct ? 'Correct!' : (data.points > 0 ? 'Partly correct (' + data.points + ' of ' + data.max + ' points).' : 'Not quite.'));
                    out.innerHTML = '';
                    const strong = document.createElement('strong');
                    strong.textContent = verdict;
                    out.appendChild(strong);
                    if (data.answer && data.correct !== true) {
                        const p = document.createElement('div');
                        p.textContent = 'Correct answer: ' + data.answer;
                        out.appendChild(p);
                    }
                    if (data.explanation) {
                        const p = document.createElement('div');
                        p.textContent = data.explanation;
                        out.appendChild(p);
                    }
                } catch (err) {
                    out.className = 'check-result is-wrong';
                    out.textContent = 'Could not check the answer. Try again.';
                } finally {
                    btn.disabled = false;
                }
            });
            form.addEventListener('input', scheduleSave);
            setInterval(() => { if (dirty) saveNow(); }, 20000);
            // --- Exam mode (locked quizzes) ---------------------------------
            // The questions stay hidden behind a start gate. Clicking "Start"
            // enters full screen (browsers only allow that from a click) and
            // starts the countdown on the server. While the exam is active:
            //  * hiding the page (tab/app switch, minimise, screen lock) or leaving
            //    it (close, reload, navigate) submits through a beacon;
            //  * leaving full screen gives a short warning, then submits;
            //  * copy, cut, paste, right-click, printing and PrintScreen are blocked.
            // Websites cannot fully block OS screenshots; the window losing focus to
            // a snipping tool still triggers the blur rule below.
            const BEGIN_URL = 'begin_quiz.php';
            const FS_GRACE_SECONDS = 5;
            const BLUR_GRACE_MS = 2000;
            let examActive = !LOCKED;
            let left = false;
            let dialogOpen = false;
            let blurTimer = null;
            let fsTimer = null;
            let fsEntered = false;

            const isFullscreen = () => !!(document.fullscreenElement || document.webkitFullscreenElement);
            function enterFullscreen() {
                const el = document.documentElement;
                const req = el.requestFullscreen || el.webkitRequestFullscreen;
                if (!req) return Promise.resolve(false);
                // Some embedded/locked-down browsers never settle the request:
                // give up after 1.5s so the quiz still starts (other locks apply).
                const timeout = new Promise(resolve => setTimeout(() => resolve(isFullscreen()), 1500));
                try {
                    const request = Promise.resolve(req.call(el, { navigationUI: 'hide' })).then(() => true, () => false);
                    return Promise.race([request, timeout]);
                } catch (err) {
                    return Promise.resolve(false);
                }
            }
            function exitFullscreen() {
                if (!isFullscreen()) return;
                const exit = document.exitFullscreen || document.webkitExitFullscreen;
                if (exit) { try { exit.call(document); } catch (err) { /* already out */ } }
            }

            let toastTimer = null;
            function toast(text) {
                const el = document.getElementById('exam-toast');
                if (!el) return;
                el.textContent = text;
                el.classList.add('show');
                clearTimeout(toastTimer);
                toastTimer = setTimeout(() => el.classList.remove('show'), 2200);
            }

            function submitOnLeave(reason) {
                if (!LOCKED || !examActive || submitting || left) return;
                left = true;
                submitting = true;
                clearTimeout(fsTimer);
                document.getElementById('fs-warning').hidden = true;
                const body = JSON.stringify({ attempt_id: ATTEMPT_ID, csrf_token: CSRF_TOKEN, reason: reason || 'left_page', answers: collectAnswers() });
                const blob = new Blob([body], { type: 'application/json' });
                if (!(navigator.sendBeacon && navigator.sendBeacon(LEAVE_URL, blob))) {
                    fetch(LEAVE_URL, { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin', body: body, keepalive: true }).catch(() => {});
                }
                exitFullscreen();
                const banner = document.getElementById('left-banner');
                banner.textContent = reason === 'exit_fullscreen'
                    ? 'You left full screen, so your quiz was submitted.'
                    : 'You left the quiz page, so your quiz was submitted.';
                banner.style.display = 'block';
                banner.scrollIntoView({ block: 'center' });
                form.querySelectorAll('input, textarea, button').forEach(el => { el.disabled = true; });
                // Show the graded attempt shortly (or as soon as the student comes back).
                setTimeout(() => { if (document.visibilityState === 'visible') window.location.replace(RESULT_URL); }, 1500);
            }

            // Start gate: enter full screen, then start the countdown on the server.
            async function beginExam() {
                const btn = document.getElementById('exam-start');
                btn.disabled = true;
                btn.textContent = 'Starting...';
                fsEntered = await enterFullscreen();
                try {
                    const res = await fetch(BEGIN_URL, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        credentials: 'same-origin',
                        body: JSON.stringify({ attempt_id: ATTEMPT_ID, csrf_token: CSRF_TOKEN })
                    });
                    const data = await res.json();
                    if (!res.ok || !data.ok) throw new Error(data.error || 'begin_failed');
                    startedAt = data.started_at;
                    deadline = data.deadline;
                    if (data.questions_html) {
                        document.getElementById('question-list').innerHTML = data.questions_html;
                        refreshUI();
                    }
                } catch (err) {
                    exitFullscreen();
                    btn.disabled = false;
                    btn.textContent = 'Try again';
                    document.getElementById('exam-gate-error').hidden = false;
                    return;
                }
                document.body.classList.remove('exam-gated');
                document.body.classList.add('exam-active');
                document.getElementById('exam-gate').hidden = true;
                examActive = true;
                tick();
            }

            if (LOCKED) {
                document.getElementById('exam-start').addEventListener('click', beginExam);
                document.getElementById('fs-return').addEventListener('click', () => {
                    enterFullscreen().then(ok => {
                        if (ok) {
                            clearTimeout(fsTimer);
                            document.getElementById('fs-warning').hidden = true;
                        }
                    });
                });
            }

            // Leaving full screen (Esc, F11, swipe): warn, then submit.
            function onFullscreenChange() {
                if (!LOCKED || !examActive || submitting || !fsEntered) return;
                const warn = document.getElementById('fs-warning');
                if (isFullscreen()) {
                    clearTimeout(fsTimer);
                    warn.hidden = true;
                    return;
                }
                let remaining = FS_GRACE_SECONDS;
                const count = document.getElementById('fs-count');
                warn.hidden = false;
                count.textContent = remaining;
                clearTimeout(fsTimer);
                const step = () => {
                    if (isFullscreen() || submitting) { warn.hidden = true; return; }
                    remaining -= 1;
                    count.textContent = Math.max(0, remaining);
                    if (remaining <= 0) { submitOnLeave('exit_fullscreen'); return; }
                    fsTimer = setTimeout(step, 1000);
                };
                fsTimer = setTimeout(step, 1000);
            }
            document.addEventListener('fullscreenchange', onFullscreenChange);
            document.addEventListener('webkitfullscreenchange', onFullscreenChange);

            document.addEventListener('visibilitychange', () => {
                if (document.visibilityState === 'hidden') {
                    if (LOCKED) submitOnLeave('left_page'); else saveBeacon();
                } else if (left) {
                    window.location.replace(RESULT_URL);
                }
            });
            window.addEventListener('pagehide', () => { if (LOCKED) submitOnLeave('left_page'); else saveBeacon(); });

            // Switching to another app while this window stays on screen (Alt+Tab,
            // split screen, a snipping tool) only blurs the window. A short grace
            // period ignores stray clicks; our own confirm dialog is excluded.
            window.addEventListener('blur', () => {
                if (!LOCKED || !examActive || submitting || dialogOpen) return;
                clearTimeout(blurTimer);
                blurTimer = setTimeout(() => {
                    if (!document.hasFocus()) submitOnLeave('left_page');
                }, BLUR_GRACE_MS);
            });
            window.addEventListener('focus', () => {
                clearTimeout(blurTimer);
                if (left) window.location.replace(RESULT_URL);
            });

            // Copy / paste / right-click / print / screenshot-key blocking.
            if (LOCKED) {
                const block = (msg) => (e) => {
                    if (!examActive || submitting) return;
                    e.preventDefault();
                    toast(msg);
                };
                ['copy', 'cut'].forEach(t => document.addEventListener(t, block('Copying is disabled during this quiz.')));
                document.addEventListener('paste', block('Pasting is disabled during this quiz.'));
                document.addEventListener('contextmenu', block('Right-click is disabled during this quiz.'));
                document.addEventListener('dragstart', block('Dragging is disabled during this quiz.'));
                document.addEventListener('drop', block('Dropping text is disabled during this quiz.'));
                document.addEventListener('selectstart', (e) => {
                    // Selecting inside your own answer box (to edit it) is fine; nowhere else.
                    const t = e.target && e.target.nodeType === 1 ? e.target : e.target && e.target.parentElement;
                    if (examActive && !(t && t.closest('textarea, input'))) e.preventDefault();
                });
                window.addEventListener('beforeprint', () => { if (examActive) toast('Printing is disabled during this quiz.'); });

                const blockedCombos = ['c', 'x', 'v', 'p', 's', 'u'];
                document.addEventListener('keydown', (e) => {
                    if (!examActive || submitting) return;
                    const key = (e.key || '').toLowerCase();
                    const mod = e.ctrlKey || e.metaKey;
                    if ((mod && blockedCombos.includes(key)) || key === 'f12' || key === 'printscreen'
                        || (mod && e.shiftKey && ['i', 'j', 'c', 's'].includes(key))) {
                        e.preventDefault();
                        toast(key === 'printscreen' ? 'Screenshots are not allowed during this quiz.' : 'That shortcut is disabled during this quiz.');
                    }
                });
                // PrintScreen often reaches the page only on keyup: blank the screen
                // briefly and overwrite the clipboard so the capture is useless.
                document.addEventListener('keyup', (e) => {
                    if (!examActive || submitting || (e.key || '').toLowerCase() !== 'printscreen') return;
                    document.body.classList.add('exam-blank');
                    if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText('').catch(() => {});
                    toast('Screenshots are not allowed during this quiz.');
                    setTimeout(() => document.body.classList.remove('exam-blank'), 1500);
                });
            }
            window.addEventListener('online', saveNow);

            window.addEventListener('beforeunload', (e) => {
                // In lock mode always ask first: leaving will submit the quiz.
                if (!submitting && (dirty || (LOCKED && examActive))) {
                    e.preventDefault();
                    e.returnValue = '';
                }
            });

            form.addEventListener('submit', (e) => {
                if (submitting) { e.preventDefault(); return; }
                if (!timeUp) {
                    const unanswered = TOTAL - answeredCount(collectAnswers());
                    const message = unanswered > 0
                        ? 'You have ' + unanswered + ' unanswered question(s). Submit anyway? You cannot change your answers afterward.'
                        : 'Submit your answers? You cannot change them afterward.';
                    dialogOpen = true;
                    const ok = confirm(message);
                    dialogOpen = false;
                    if (!ok) {
                        e.preventDefault();
                        return;
                    }
                }
                submitting = true;
                submitBtn.disabled = true;
                submitBtn.textContent = 'Submitting...';
                exitFullscreen(); // quiz over: back to the normal browser view
            });

            // --- Timer ----------------------------------------------------
            function formatClock(totalSeconds) {
                const m = Math.floor(totalSeconds / 60);
                const s = totalSeconds % 60;
                return m + ':' + String(s).padStart(2, '0');
            }

            function autoSubmit() {
                if (submitting) return;
                timeUp = true;
                submitting = true;
                document.getElementById('auto-field').value = 'time_up';
                document.getElementById('time-up-banner').style.display = 'block';
                exitFullscreen();
                form.submit();
            }

            function tick() {
                if (deadline !== null) {
                    const remaining = deadline - serverNow();
                    if (remaining <= 0) {
                        timerEl.textContent = '0:00';
                        autoSubmit();
                        return;
                    }
                    timerEl.textContent = formatClock(Math.floor(remaining / 1000));
                    timerEl.classList.toggle('low-time', remaining < 60000);
                } else {
                    const elapsed = Math.floor((serverNow() - startedAt) / 1000);
                    timerEl.textContent = formatClock(Math.max(0, elapsed));
                }
                setTimeout(tick, 1000);
            }

            refreshUI();
            // Locked quizzes start the clock from the full-screen gate (beginExam).
            if (!LOCKED) tick();
        })();
    </script>
</body>

</html>
