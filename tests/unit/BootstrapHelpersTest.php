<?php
// Request/session helpers added with includes/bootstrap.php.

test('is_https() detects direct HTTPS, port 443 and a TLS proxy', function () {
    $saved = $_SERVER;
    try {
        $_SERVER = [];
        assert_false(is_https());
        $_SERVER = ['HTTPS' => 'on'];
        assert_true(is_https());
        $_SERVER = ['HTTPS' => 'off'];
        assert_false(is_https());
        $_SERVER = ['SERVER_PORT' => '443'];
        assert_true(is_https());
        $_SERVER = ['HTTP_X_FORWARDED_PROTO' => 'https'];
        assert_true(is_https());
    } finally {
        $_SERVER = $saved;
    }
});

test('wants_json() reads the request content type and Accept header', function () {
    $saved = $_SERVER;
    try {
        $_SERVER = [];
        assert_false(wants_json());
        $_SERVER = ['CONTENT_TYPE' => 'application/json; charset=utf-8'];
        assert_true(wants_json());
        $_SERVER = ['HTTP_ACCEPT' => 'text/html,application/json'];
        assert_true(wants_json());
        $_SERVER = ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'];
        assert_false(wants_json());
    } finally {
        $_SERVER = $saved;
    }
});

test('flash() messages are returned once, in order', function () {
    $saved = $_SESSION ?? null;
    $_SESSION = [];
    try {
        assert_same([], take_flashes());
        flash('success', 'Saved.');
        flash('error', 'Oops.');
        assert_same([['type' => 'success', 'message' => 'Saved.'], ['type' => 'error', 'message' => 'Oops.']], take_flashes());
        assert_same([], take_flashes());
    } finally {
        $_SESSION = $saved;
    }
});
