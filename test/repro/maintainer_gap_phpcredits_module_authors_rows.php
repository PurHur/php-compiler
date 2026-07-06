<?php

declare(strict_types=1);

/**
 * Maintainer repro: phpcredits(CREDITS_MODULES) lists loaded extension rows (#16338).
 */

ob_start();
phpcredits(CREDITS_MODULES);
$out = ob_get_clean();

$requiredWhenLoaded = [
    'curl' => 'cURL',
    'mbstring' => 'Multibyte String Functions',
    'json' => 'JSON',
];

foreach ($requiredWhenLoaded as $ext => $row) {
    if (extension_loaded($ext) && !str_contains($out, $row)) {
        fwrite(STDERR, "FAIL: CREDITS_MODULES missing {$row} row\n");
        exit(1);
    }
    if (!extension_loaded($ext) && str_contains($out, $row)) {
        fwrite(STDERR, "FAIL: CREDITS_MODULES lists {$row} but extension_loaded('{$ext}') is false\n");
        exit(1);
    }
}

if (!extension_loaded('openssl') && str_contains($out, 'OpenSSL')) {
    fwrite(STDERR, "FAIL: CREDITS_MODULES lists OpenSSL but extension_loaded('openssl') is false\n");
    exit(1);
}

if (!str_contains($out, 'Perl Compatible Regexps')) {
    fwrite(STDERR, "FAIL: CREDITS_MODULES missing PCRE row\n");
    exit(1);
}

$len = \strlen($out);
if ($len < 1000) {
    fwrite(STDERR, "FAIL: CREDITS_MODULES output too short ({$len} bytes)\n");
    exit(1);
}

echo "ok len={$len}\n";
