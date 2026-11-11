<?php
/**
 * Front matter for every web page and endpoint:
 *
 *   require_once __DIR__ . '/../../includes/bootstrap.php';
 *
 * Loads config, installs the error handler, opens the database ($conn) and
 * brings the schema up to date, loads the shared helpers, and starts the
 * session with secure cookie settings. Pages then call require_role() (or
 * require_role_json() for JSON endpoints) to guard themselves.
 *
 * Must be included at file scope so $conn is visible to the page.
 */

require_once __DIR__ . '/../db/config.php';
require_once APP_ROOT . '/includes/helpers.php';

install_error_handler();
send_security_headers();

require_once APP_ROOT . '/db/conn.php';
require_once APP_ROOT . '/includes/quiz_helpers.php';
require_once APP_ROOT . '/includes/class_helpers.php';
require_once APP_ROOT . '/includes/question_bank.php';
require_once APP_ROOT . '/includes/assignments.php';
require_once APP_ROOT . '/includes/notifications.php';
require_once APP_ROOT . '/includes/practice.php';
require_once APP_ROOT . '/includes/analytics.php';
require_once APP_ROOT . '/includes/ui.php';
require_once APP_ROOT . '/includes/auth_layout.php';

start_app_session();
