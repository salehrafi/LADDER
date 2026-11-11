<?php
/**
 * Opens the application database connection ($conn) and makes sure the
 * schema exists. Every page includes this file first.
 */
require_once __DIR__ . '/config.php';

try {
    $conn = new mysqli(
        (string) env_value('server_name', 'localhost'),
        (string) env_value('username', 'root'),
        (string) env_value('db_password', ''),
        (string) env_value('db_name', '')
    );
} catch (mysqli_sql_exception $e) {
    error_log('DB connection failed: ' . $e->getMessage());
    http_response_code(503);
    die('The site is temporarily unavailable. Please try again in a few minutes.');
}

$conn->set_charset('utf8mb4');
// Keep MySQL's NOW() in the same offset as PHP (e.g. +06:00).
$conn->query("SET time_zone = '" . date('P') . "'");

require_once __DIR__ . '/schema.php';
