<?php

declare(strict_types=1);

ob_start();
phpcredits(CREDITS_MODULES);
$out = ob_get_clean();

$fail = 0;

if (!extension_loaded('curl') && str_contains($out, 'cURL')) {
    fwrite(STDERR, "FAIL: CREDITS_MODULES lists cURL but extension_loaded('curl') is false\n");
    ++$fail;
}

if (extension_loaded('curl') && !str_contains($out, 'cURL')) {
    fwrite(STDERR, "FAIL: CREDITS_MODULES missing cURL but extension is loaded\n");
    ++$fail;
}

if (extension_loaded('mbstring') && !str_contains($out, 'Multibyte String Functions')) {
    fwrite(STDERR, "FAIL: CREDITS_MODULES missing mbstring row\n");
    ++$fail;
}

if (extension_loaded('json') && !str_contains($out, 'JSON')) {
    fwrite(STDERR, "FAIL: CREDITS_MODULES missing JSON row\n");
    ++$fail;
}

if (!extension_loaded('openssl') && str_contains($out, 'OpenSSL')) {
    fwrite(STDERR, "FAIL: CREDITS_MODULES lists OpenSSL but extension_loaded('openssl') is false\n");
    ++$fail;
}

$len = \strlen($out);
if ($len > 5500 && !extension_loaded('curl')) {
    fwrite(STDERR, "FAIL: CREDITS_MODULES output too long ({$len} bytes) for reference profile without curl\n");
    ++$fail;
}

if (0 !== $fail) {
    exit(1);
}

echo extension_loaded('curl') ? "ok curl-loaded len={$len}\n" : "skip: curl not loaded len={$len}\n";
