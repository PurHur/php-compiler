<?php

declare(strict_types=1);

$checks = [
    'strcasecmp' => strcasecmp('a', null),
    'strnatcmp' => strnatcmp('a', null),
    'strcoll' => strcoll('a', null),
];

foreach ($checks as $fn => $result) {
    if (!is_int($result)) {
        fwrite(STDERR, "{$fn}: expected int, got ".gettype($result)."\n");
        exit(1);
    }
    echo "{$fn}={$result}\n";
}

echo "ok\n";
