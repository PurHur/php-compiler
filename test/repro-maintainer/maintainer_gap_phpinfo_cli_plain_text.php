<?php

/** Issue #16489 — phpinfo(INFO_GENERAL) must emit plain-text rows in CLI SAPI. */
ob_start();
phpinfo(INFO_GENERAL);
$out = ob_get_clean();

if (str_starts_with($out, '<!DOCTYPE')) {
    echo "fail: phpinfo(INFO_GENERAL) emitted HTML in cli\n";
    exit(1);
}
if (!str_contains($out, 'PHP Version =>')) {
    echo "fail: missing PHP Version row\n";
    exit(1);
}
if (!str_contains($out, 'Configuration File (php.ini) Path =>')) {
    echo "fail: missing ini path row\n";
    exit(1);
}
echo "ok cli-general len=".strlen($out)."\n";
