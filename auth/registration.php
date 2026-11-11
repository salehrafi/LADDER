<?php
/**
 * Sign-up lives on the combined sign-in / sign-up page. This route serves
 * that page with the sign-up tab open, so existing links and forms still work.
 */
$auth_default_mode = 'signup';
require __DIR__ . '/login.php';
