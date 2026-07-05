<?php

declare(strict_types=1);

ob_start();
phpcredits(CREDITS_MODULES);
$out = ob_get_clean();

$expected = [
    'curl' => 'cURL',
    'mbstring' => 'Multibyte String Functions',
    'openssl' => 'OpenSSL',
    'json' => 'JSON',
];

foreach ($expected as $ext => $row) {
    if (!extension_loaded($ext)) {
        if (str_contains($out, $row)) {
            fwrite(STDERR, "FAIL: CREDITS_MODULES lists {$row} but extension_loaded('{$ext}') is false\n");
            exit(1);
        }
        continue;
    }
    if (!str_contains($out, $row)) {
        fwrite(STDERR, "FAIL: CREDITS_MODULES missing {$row} row\n");
        exit(1);
    }
}

$len = \strlen($out);
$minLen = extension_loaded('curl') ? 3900 : 1200;
if ($len < $minLen) {
    fwrite(STDERR, "FAIL: CREDITS_MODULES output too short ({$len} bytes, expected >={$minLen})\n");
    exit(1);
}

echo "ok len={$len}\n";
