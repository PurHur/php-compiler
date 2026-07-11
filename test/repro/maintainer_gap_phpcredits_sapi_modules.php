<?php

declare(strict_types=1);

ob_start();
phpcredits(CREDITS_SAPI);
$out = ob_get_clean();

if (!str_contains($out, 'SAPI Modules')) {
    fwrite(STDERR, "FAIL: CREDITS_SAPI missing SAPI Modules section\n");
    exit(1);
}
if (!str_contains($out, 'CGI / FastCGI')) {
    fwrite(STDERR, "FAIL: CREDITS_SAPI missing CGI / FastCGI row\n");
    exit(1);
}
if (str_contains($out, 'Server API (SAPI) Abstraction Layer</h2>')) {
    fwrite(STDERR, "FAIL: CREDITS_SAPI must not render abstraction-layer blurb\n");
    exit(1);
}

echo "ok len=".\strlen($out)."\n";
