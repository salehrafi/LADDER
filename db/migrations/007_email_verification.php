<?php
/**
 * Email verification for public sign-ups.
 *
 * - reg_info.email_verified: 0 until the owner opens the link we email them.
 *   It defaults to 1, so every existing account and every account a teacher
 *   creates for a student (CSV import, add student, demo seed) stays usable
 *   without a link. Only the public sign-up form inserts 0.
 * - verify_token_hash: SHA-256 of the emailed token (the token itself is never
 *   stored), unique so a link can only ever match one account.
 * - verify_expires: when the current link stops working.
 */
return function (mysqli $conn): void {
    ensure_column($conn, 'reg_info', 'email_verified', 'TINYINT(1) NOT NULL DEFAULT 1');
    ensure_column($conn, 'reg_info', 'verify_token_hash', 'CHAR(64) NULL');
    ensure_column($conn, 'reg_info', 'verify_expires', 'DATETIME NULL');
    ensure_index($conn, 'reg_info', 'uq_verify_token', 'UNIQUE KEY uq_verify_token (verify_token_hash)');
};
