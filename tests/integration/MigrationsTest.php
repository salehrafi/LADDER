<?php
// Versioned schema migrations (db/migrate.php, db/migrations/*.php).

require_once APP_ROOT . '/db/migrate.php';

test('migration files are found in version order with valid names', function () {
    $files = migration_files();
    assert_true(count($files) >= 1, 'expected at least the baseline migration');
    assert_same(array_keys($files), array_values(array_unique(array_keys($files))));
    $sorted = array_keys($files);
    sort($sorted);
    assert_same($sorted, array_keys($files));
    assert_same('001_baseline.php', basename($files[1]));
});

test('every migration is recorded and a second run applies nothing', function () {
    $conn = test_db();
    assert_same(array_keys(migration_files()), applied_migrations($conn));
    assert_same([], run_migrations($conn));
});

test('the baseline migration is idempotent on an existing schema', function () {
    $conn = test_db();
    reset_test_db();
    $quiz = make_quiz($conn, [['short_answer', 'Q', 1, 'yes', []]]);

    $baseline = require APP_ROOT . '/db/migrations/001_baseline.php';
    $baseline($conn); // must not throw or touch data

    $count = (int) $conn->query("SELECT COUNT(*) AS c FROM questions WHERE quiz_id = " . (int) $quiz['quiz_id'])->fetch_assoc()['c'];
    assert_same(1, $count);
    assert_true(column_exists($conn, 'quizzes', 'lock_on_leave'));
    assert_true(column_exists($conn, 'reg_info', 'must_change_password'));
});

test('a missing migration runs once and is recorded', function () {
    $conn = test_db();
    $conn->query("DELETE FROM schema_migrations WHERE version = 1");
    assert_same([1], run_migrations($conn));
    assert_same([], run_migrations($conn));
});

test('ensure_column() and ensure_index() add only when missing', function () {
    $conn = test_db();
    $conn->query("DROP TABLE IF EXISTS migration_probe");
    $conn->query("CREATE TABLE migration_probe (id INT PRIMARY KEY)");

    ensure_column($conn, 'migration_probe', 'note', 'VARCHAR(20) NULL');
    ensure_column($conn, 'migration_probe', 'note', 'VARCHAR(20) NULL');
    assert_true(column_exists($conn, 'migration_probe', 'note'));

    assert_false(index_exists($conn, 'migration_probe', 'idx_note'));
    ensure_index($conn, 'migration_probe', 'idx_note', 'INDEX idx_note (note)');
    ensure_index($conn, 'migration_probe', 'idx_note', 'INDEX idx_note (note)');
    assert_true(index_exists($conn, 'migration_probe', 'idx_note'));

    $conn->query("DROP TABLE migration_probe");
});

test('a failing statement raises an error naming the statement', function () {
    $conn = test_db();
    try {
        migration_exec($conn, "ALTER TABLE no_such_table_xyz ADD COLUMN c INT");
        fail('expected an exception');
    } catch (RuntimeException $e) {
        assert_contains('no_such_table_xyz', $e->getMessage());
    }
});
