<?php
/**
 * Brings the database schema up to date. Expects $conn (mysqli).
 *
 * The schema lives in versioned files under db/migrations/ (see
 * db/migrate.php). On a normal request this is one quick lookup in
 * schema_migrations; new migrations run once, on the first request after
 * they are deployed.
 */
require_once __DIR__ . '/migrate.php';

try {
    run_migrations($conn);
} catch (Throwable $e) {
    error_log('Schema migration failed: ' . $e->getMessage());
    if (PHP_SAPI === 'cli') {
        throw $e;
    }
    http_response_code(500);
    die('Database setup failed. Check the server logs.');
}
