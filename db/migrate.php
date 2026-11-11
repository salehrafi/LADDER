<?php
/**
 * Versioned schema migrations.
 *
 * Each file in db/migrations/ is named NNN_short_name.php and returns a
 * function (mysqli $conn): void. Applied versions are recorded in
 * schema_migrations, so on a normal request the only cost is one small query.
 *
 * Migrations run automatically on the first request after a deploy (shared
 * hosts have no shell), under a MySQL named lock so two requests can never
 * run the same migration at once.
 *
 * MySQL commits DDL immediately (no rollback), so every migration must be
 * safe to run again if it stopped half way: use CREATE TABLE IF NOT EXISTS,
 * ensure_column(), ensure_index() and similar guards.
 */

const MIGRATIONS_DIR = __DIR__ . '/migrations';
const MIGRATION_LOCK = 'quiz_app_schema_migrate';

/** @return array<int,string> version => file path, in order */
function migration_files(): array
{
    $files = [];
    foreach (glob(MIGRATIONS_DIR . '/*.php') ?: [] as $path) {
        if (preg_match('/^(\d{3,})_[a-z0-9_]+\.php$/', basename($path), $m)) {
            $files[(int) $m[1]] = $path;
        }
    }
    ksort($files);
    return $files;
}

/** @return int[] versions already applied to this database */
function applied_migrations(mysqli $conn): array
{
    $result = $conn->query("SELECT version FROM schema_migrations ORDER BY version");
    return array_map('intval', array_column($result->fetch_all(MYSQLI_ASSOC), 'version'));
}

/**
 * Bring the database schema up to date.
 *
 * @return int[] versions applied by this call (empty when already up to date)
 */
function run_migrations(mysqli $conn): array
{
    $files = migration_files();
    if (!$files) {
        return [];
    }

    $conn->query(
        "CREATE TABLE IF NOT EXISTS schema_migrations (
            version INT NOT NULL PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )"
    );

    // Fast path: nothing new since the last request.
    $pending = array_diff(array_keys($files), applied_migrations($conn));
    if (!$pending) {
        return [];
    }

    $lock = $conn->query("SELECT GET_LOCK('" . MIGRATION_LOCK . "', 30) AS got")->fetch_assoc();
    if ((int) ($lock['got'] ?? 0) !== 1) {
        throw new RuntimeException('Timed out waiting for another request to finish migrating the database.');
    }

    $ran = [];
    try {
        // Another request may have finished while we waited for the lock.
        $applied = applied_migrations($conn);
        foreach ($files as $version => $path) {
            if (in_array($version, $applied, true)) {
                continue;
            }
            $migration = require $path;
            if (!is_callable($migration)) {
                throw new RuntimeException("Migration $path must return a function.");
            }
            $migration($conn);

            $name = substr(basename($path, '.php'), 0, 100);
            $stmt = $conn->prepare("INSERT INTO schema_migrations (version, name) VALUES (?, ?)");
            $stmt->bind_param("is", $version, $name);
            $stmt->execute();
            $stmt->close();
            $ran[] = $version;
        }
    } finally {
        $conn->query("SELECT RELEASE_LOCK('" . MIGRATION_LOCK . "')");
    }
    return $ran;
}

/** Run one DDL statement; throw with the statement in the message if it fails. */
function migration_exec(mysqli $conn, string $sql): void
{
    try {
        $ok = $conn->query($sql);
    } catch (mysqli_sql_exception $e) {
        throw new RuntimeException('Migration statement failed: ' . $e->getMessage() . "\n" . $sql, 0, $e);
    }
    if ($ok !== true) {
        throw new RuntimeException('Migration statement failed: ' . $conn->error . "\n" . $sql);
    }
}

function column_exists(mysqli $conn, string $table, string $column): bool
{
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS c FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $stmt->bind_param("ss", $table, $column);
    $stmt->execute();
    $exists = (int) $stmt->get_result()->fetch_assoc()['c'] > 0;
    $stmt->close();
    return $exists;
}

function index_exists(mysqli $conn, string $table, string $index): bool
{
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS c FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?"
    );
    $stmt->bind_param("ss", $table, $index);
    $stmt->execute();
    $exists = (int) $stmt->get_result()->fetch_assoc()['c'] > 0;
    $stmt->close();
    return $exists;
}

/** Add a column if it is missing (CREATE TABLE IF NOT EXISTS never alters an existing table). */
function ensure_column(mysqli $conn, string $table, string $column, string $definition): void
{
    if (!column_exists($conn, $table, $column)) {
        migration_exec($conn, "ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    }
}

function foreign_key_exists(mysqli $conn, string $table, string $name): bool
{
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS c FROM information_schema.TABLE_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
    );
    $stmt->bind_param("ss", $table, $name);
    $stmt->execute();
    $exists = (int) $stmt->get_result()->fetch_assoc()['c'] > 0;
    $stmt->close();
    return $exists;
}

/** Add a named foreign key if it is missing. $definition is "FOREIGN KEY (col) REFERENCES t(col) ON ...". */
function ensure_foreign_key(mysqli $conn, string $table, string $name, string $definition): void
{
    if (!foreign_key_exists($conn, $table, $name)) {
        migration_exec($conn, "ALTER TABLE `$table` ADD CONSTRAINT `$name` $definition");
    }
}

/** Add an index if it is missing. $definition is e.g. "INDEX idx_x (a, b)" or "UNIQUE KEY uq_x (a)". */
function ensure_index(mysqli $conn, string $table, string $index, string $definition): void
{
    if (!index_exists($conn, $table, $index)) {
        migration_exec($conn, "ALTER TABLE `$table` ADD $definition");
    }
}
