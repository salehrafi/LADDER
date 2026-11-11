<?php
/**
 * AI question import end to end: preview pasted Markdown, import an uploaded
 * .md file, refuse broken files, and keep other teachers out. Uses only
 * @http-test.local accounts, removed afterwards.
 */

require_once __DIR__ . '/support.php';
require_once APP_ROOT . '/includes/question_import.php';

$GLOBALS['imp'] = [];

test('import setup: a teacher with an empty quiz', function () {
    http_cleanup();
    http_make_account('teacher', 'imp1@http-test.local', 'Import Teacher');
    http_make_account('teacher', 'imp2@http-test.local', 'Other Teacher');
    $db = http_app_db();
    $db->query("INSERT INTO quizzes (teacher_email, title, status) VALUES ('imp1@http-test.local', 'HTTP Import Quiz', 'draft')");
    $GLOBALS['imp'] = ['quiz_id' => (int) $db->insert_id, 't1' => http_login('imp1@http-test.local')];
});

test('questions page shows the prompt builder and a sample file', function () {
    $t = $GLOBALS['imp']['t1'] ?? fail('setup failed');
    $page = 'dashboards/teachersPages/quiz_questions.php?quiz_id=' . $GLOBALS['imp']['quiz_id'];
    $body = $t->get($page)->body;
    assert_contains('Generate questions with AI', $body);
    assert_contains('Copy prompt', $body);
    assert_contains('Topic: Photosynthesis', $body, 'demo prompt filled in');

    $t->get($page . '&sample_md=1');
    assert_same(sample_question_markdown(), $t->body);
});

test('preview shows the questions and saves nothing', function () {
    $t = $GLOBALS['imp']['t1'] ?? fail('setup failed');
    $quiz_id = $GLOBALS['imp']['quiz_id'];
    $page = "dashboards/teachersPages/quiz_questions.php?quiz_id=$quiz_id";
    $token = $t->get($page)->csrf();
    $t->post($page, ['csrf_token' => $token, 'action' => 'import_markdown', 'quiz_id' => $quiz_id, 'mode' => 'preview',
        'markdown' => "## Q1 [mcq] (1 point)\nWhich <b>tag</b>?\n- [x] Yes\n- [ ] No\n"]);
    assert_same(200, $t->status);
    assert_contains('Preview: 1 question(s)', $t->body);
    assert_contains('Which &lt;b&gt;tag&lt;/b&gt;?', $t->body, 'escaped');
    assert_same(0, (int) http_app_db()->query("SELECT COUNT(*) c FROM questions WHERE quiz_id = $quiz_id")->fetch_assoc()['c']);
});

test('a broken file imports nothing and lists the problems', function () {
    $t = $GLOBALS['imp']['t1'] ?? fail('setup failed');
    $quiz_id = $GLOBALS['imp']['quiz_id'];
    $page = "dashboards/teachersPages/quiz_questions.php?quiz_id=$quiz_id";
    $token = $t->get($page)->csrf();
    $t->post($page, ['csrf_token' => $token, 'action' => 'import_markdown', 'quiz_id' => $quiz_id, 'mode' => 'import',
        'markdown' => "## Q1 [short]\nOk?\nAnswer: yes\n\n## Q2 [mcq]\nPick\n- [ ] A\n- [ ] B\n"]);
    assert_contains('Nothing was imported', $t->body);
    assert_contains('Question 2 (line 5): no option is marked correct', $t->body);
    assert_same(0, (int) http_app_db()->query("SELECT COUNT(*) c FROM questions WHERE quiz_id = $quiz_id")->fetch_assoc()['c']);
});

test('uploading an AI-written .md file adds the questions', function () {
    $t = $GLOBALS['imp']['t1'] ?? fail('setup failed');
    $quiz_id = $GLOBALS['imp']['quiz_id'];
    $page = "dashboards/teachersPages/quiz_questions.php?quiz_id=$quiz_id";
    $token = $t->get($page)->csrf();

    $file = tempnam(sys_get_temp_dir(), 'md') . '.md';
    file_put_contents($file, sample_question_markdown());
    $t->post($page, ['csrf_token' => $token, 'action' => 'import_markdown', 'quiz_id' => $quiz_id, 'mode' => 'import',
        'markdown' => '', 'md_file' => new CURLFile($file, 'text/markdown', 'questions.md')]);
    @unlink($file);

    assert_same(302, $t->status, 'redirects after import so a refresh cannot import twice');
    assert_contains('imported=4', $t->location);
    assert_contains('4 question(s) imported', $t->get($page . '&imported=4')->body);
    assert_contains('Chlorophyll', $t->body);
    assert_same(4, (int) http_app_db()->query("SELECT COUNT(*) c FROM questions WHERE quiz_id = $quiz_id")->fetch_assoc()['c']);
});

test('a non-Markdown upload is refused', function () {
    $t = $GLOBALS['imp']['t1'] ?? fail('setup failed');
    $quiz_id = $GLOBALS['imp']['quiz_id'];
    $page = "dashboards/teachersPages/quiz_questions.php?quiz_id=$quiz_id";
    $token = $t->get($page)->csrf();
    $file = tempnam(sys_get_temp_dir(), 'md') . '.php';
    file_put_contents($file, "## Q1 [short]\nX?\n");
    $t->post($page, ['csrf_token' => $token, 'action' => 'import_markdown', 'quiz_id' => $quiz_id, 'mode' => 'import',
        'md_file' => new CURLFile($file, 'text/plain', 'evil.php')]);
    @unlink($file);
    assert_contains('Upload a Markdown file', $t->body);
    assert_same(4, (int) http_app_db()->query("SELECT COUNT(*) c FROM questions WHERE quiz_id = $quiz_id")->fetch_assoc()['c']);
});

test("another teacher cannot import into someone else's quiz", function () {
    $quiz_id = $GLOBALS['imp']['quiz_id'] ?? fail('setup failed');
    $t2 = http_login('imp2@http-test.local');
    $token = $t2->get('dashboards/teachersPages/setQuiz.php')->csrf();
    $t2->post("dashboards/teachersPages/quiz_questions.php?quiz_id=$quiz_id", ['csrf_token' => $token, 'action' => 'import_markdown',
        'quiz_id' => $quiz_id, 'mode' => 'import', 'markdown' => "## Q1 [short]\nHacked?\nAnswer: yes\n"]);
    assert_same(302, $t2->status);
    assert_contains('setQuiz.php', $t2->location);
    assert_same(4, (int) http_app_db()->query("SELECT COUNT(*) c FROM questions WHERE quiz_id = $quiz_id")->fetch_assoc()['c']);
});

test('import teardown', function () {
    http_cleanup();
    assert_same(0, (int) http_app_db()->query("SELECT COUNT(*) c FROM quizzes WHERE title = 'HTTP Import Quiz'")->fetch_assoc()['c']);
});
