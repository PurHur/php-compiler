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
    'Registered PHP Streams',
    'Zend Signal Handling',
] as $row) {
    if (!str_contains($out, $row)) {
        fwrite(STDERR, "FAIL: phpinfo(INFO_GENERAL) missing {$row}\n");
        exit(1);
    }
}

$len = \strlen($out);
if ($len < 1200) {
    fwrite(STDERR, "FAIL: phpinfo(INFO_GENERAL) output too short ({$len} bytes, expected >=1200)\n");
    exit(1);
}

echo "ok len={$len}\n";
