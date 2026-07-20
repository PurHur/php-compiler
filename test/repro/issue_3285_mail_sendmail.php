<?php

/**
 * Repro #3285 — mail() via mock sendmail_path (php-src ext/standard/mail.c).
 *
 * Run:
 *   php bin/vm.php -d sendmail_path="$(pwd)/test/compliance/cases/stdlib/mail_fixtures/mock_sendmail.sh" \
 *     test/repro/issue_3285_mail_sendmail.php
 */
$mock = ini_get('sendmail_path');
if (!is_string($mock) || !is_executable($mock)) {
    fwrite(STDERR, "BAD_SENDMAIL_PATH: ".var_export($mock, true)."\n");
    exit(1);
}
$out = dirname($mock).'/mock_sendmail.last';
@unlink($out);

if (!function_exists('mail')) {
    fwrite(STDERR, "MISSING: mail\n");
    exit(1);
}

$ok = mail('user@example.com', 'Subject', "Body line\n", "From: noreply@example.com\r\n");
var_export($ok);
echo "\n";
if (!$ok || !is_file($out)) {
    fwrite(STDERR, "FAIL: transport did not capture message\n");
    exit(1);
}
$raw = file_get_contents($out);
if (!str_contains($raw, 'To: user@example.com') || !str_contains($raw, 'Body line')) {
    fwrite(STDERR, "FAIL: capture malformed\n");
    exit(1);
}
echo "OK\n";
@unlink($out);
