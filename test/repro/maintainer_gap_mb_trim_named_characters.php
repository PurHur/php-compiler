<?php

declare(strict_types=1);

if (!function_exists('mb_trim')) {
    echo "fail: mb_trim not registered (set PHP_COMPILER_PROFILE=8.4)\n";
    exit(1);
}

$s = '--héllo--';
$checks = [
    mb_trim($s, '-') === 'héllo',
    mb_trim($s, characters: '-') === 'héllo',
    mb_ltrim($s, characters: '-') === 'héllo--',
    mb_rtrim($s, characters: '-') === '--héllo',
    mb_trim($s, encoding: 'UTF-8') === '--héllo--',
];

foreach ($checks as $ok) {
    if (!$ok) {
        echo "fail: mb_trim named characters parity\n";
        exit(1);
    }
}

echo "ok\n";
