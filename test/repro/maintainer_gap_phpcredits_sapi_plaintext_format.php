<?php

declare(strict_types=1);

ob_start();
phpcredits(CREDITS_SAPI);
$out = ob_get_clean();

if (str_contains($out, '<table>')) {
    fwrite(STDERR, "FAIL: CREDITS_SAPI must not emit HTML table markup\n");
    exit(1);
}
if (!str_contains($out, 'SAPI Modules')) {
    fwrite(STDERR, "FAIL: CREDITS_SAPI missing SAPI Modules section\n");
    exit(1);
}
if (!str_contains($out, 'Contribution => Authors')) {
    fwrite(STDERR, "FAIL: CREDITS_SAPI missing plain-text column header\n");
    exit(1);
}
if (!str_contains($out, 'CGI / FastCGI')) {
    fwrite(STDERR, "FAIL: CREDITS_SAPI missing CGI / FastCGI row\n");
    exit(1);
}
if (strlen($out) > 800) {
    fwrite(STDERR, 'FAIL: CREDITS_SAPI output too long (HTML?) len='.strlen($out)."\n");
    exit(1);
}

echo 'ok len='.strlen($out)."\n";
