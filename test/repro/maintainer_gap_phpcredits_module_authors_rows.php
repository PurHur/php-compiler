<?php

declare(strict_types=1);

ob_start();
phpcredits(CREDITS_MODULES);
$out = ob_get_clean();

$requiredRows = [
    'cURL',
    'Multibyte String Functions',
    'OpenSSL',
    'JSON',
    'Perl Compatible Regexps',
];

foreach ($requiredRows as $row) {
    if (!str_contains($out, $row)) {
        fwrite(STDERR, "FAIL: CREDITS_MODULES missing {$row} row\n");
        exit(1);
    }
}

$len = \strlen($out);
if ($len < 3900) {
    fwrite(STDERR, "FAIL: CREDITS_MODULES output too short ({$len} bytes, expected >=3900)\n");
    exit(1);
}

echo "ok len={$len}\n";
