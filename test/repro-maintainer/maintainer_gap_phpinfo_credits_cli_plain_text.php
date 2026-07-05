<?php

/** Issue #16489 — phpinfo(INFO_CREDITS) must emit plain-text credits in CLI SAPI. */
ob_start();
phpinfo(INFO_CREDITS);
$out = ob_get_clean();

if (str_starts_with($out, '<!DOCTYPE')) {
    echo "fail: phpinfo(INFO_CREDITS) emitted HTML in cli\n";
    exit(1);
}
foreach (['PHP Credits', 'SAPI Modules', 'Module Authors', 'PHP Authors'] as $needle) {
    if (!str_contains($out, $needle)) {
        echo "fail: missing {$needle}\n";
        exit(1);
    }
}
echo "ok cli-credits len=".strlen($out)."\n";
