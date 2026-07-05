<?php

declare(strict_types=1);

ob_start();
phpinfo(INFO_GENERAL);
$out = ob_get_clean();

foreach ([
    'Configuration File (php.ini) Path',
    'Loaded Configuration File',
    'PHP API',
    'PHP Extension',
    'Zend Extension',
] as $row) {
    if (!str_contains($out, $row)) {
        fwrite(STDERR, "FAIL: phpinfo(INFO_GENERAL) missing {$row}\n");
        exit(1);
    }
}

$len = \strlen($out);
$isText = !str_starts_with($out, '<!DOCTYPE');
if ($isText) {
    if ($len < 700) {
        fwrite(STDERR, "FAIL: phpinfo(INFO_GENERAL) plain-text output too short ({$len} bytes, expected >=700)\n");
        exit(1);
    }
} elseif ($len < 1800) {
    fwrite(STDERR, "FAIL: phpinfo(INFO_GENERAL) output too short ({$len} bytes, expected >=1800)\n");
    exit(1);
}

echo "ok len={$len}\n";
