<?php
/**
 * Application configuration: loads .env and exposes env_value().
 *
 * Kept separate from conn.php so code that needs settings but its own
 * connection (the test suite, CLI scripts) can load config without opening
 * the application database.
 */
require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

// .env is optional: on some hosts you set real environment variables instead.
// Mutable so .env wins over inherited variables; on Windows, `username`
// would otherwise resolve to the OS login name (USERNAME) and be ignored.
if (file_exists(APP_ROOT . '/.env') && !defined('APP_ENV_LOADED')) {
    Dotenv::createMutable(APP_ROOT)->load();
    define('APP_ENV_LOADED', true);
}

if (!function_exists('env_value')) {
    /**
     * Read a config value from $_ENV, then the process environment, then a default.
     */
    function env_value(string $key, $default = null)
    {
        $value = $_ENV[$key] ?? getenv($key);
        return ($value === false || $value === null || $value === '') ? $default : $value;
    }
}

// Quizzes and deadlines are compared in PHP time, so PHP and MySQL must agree
// on the timezone. Default to Bangladesh time; override with APP_TIMEZONE.
date_default_timezone_set(env_value('APP_TIMEZONE', 'Asia/Dhaka'));

// Hide PHP errors from visitors unless APP_DEBUG=true (useful locally, never in production).
if (env_value('APP_DEBUG', 'false') === 'true') {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
}
