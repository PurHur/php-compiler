<?php

/** Issue #12482 — mail() registered on VM (ext/standard/mail.c). */
if (!function_exists('mail')) {
    echo "FAIL: mail() not registered\n";
    exit(1);
}
$result = @mail('user@example.com', 'subject', 'body');
var_export($result);
echo "\n";
if (false !== $result) {
    echo "FAIL: expected false when sendmail unavailable\n";
    exit(1);
}
echo "ok\n";
