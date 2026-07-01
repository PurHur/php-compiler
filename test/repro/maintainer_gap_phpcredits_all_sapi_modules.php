<?php

declare(strict_types=1);

ob_start();
phpcredits(CREDITS_ALL);
$out = ob_get_clean();

if (!str_contains($out, 'SAPI Modules')) {
    fwrite(STDERR, "FAIL: CREDITS_ALL missing SAPI Modules section\n");
    exit(1);
}
if (!str_contains($out, 'CGI / FastCGI')) {
    fwrite(STDERR, "FAIL: CREDITS_ALL missing CGI / FastCGI row\n");
    exit(1);
}

$len = \strlen($out);
if ($len < 6000) {
    fwrite(STDERR, "FAIL: CREDITS_ALL output too short ({$len} bytes, expected >=6000 with SAPI Modules)\n");
    exit(1);
}

echo "ok len={$len}\n";
