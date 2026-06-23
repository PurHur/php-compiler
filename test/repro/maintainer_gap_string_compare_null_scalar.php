<?php

declare(strict_types=1);

foreach ([
    'strnatcmp' => fn() => strnatcmp(null, '1'),
    'strcoll' => fn() => strcoll(null, 'a'),
    'strncmp' => fn() => strncmp(null, 'a', 1),
    'version_compare' => fn() => version_compare(null, '1.0'),
    'strcmp' => fn() => strcmp(true, '1'),
] as $name => $fn) {
    try {
        $fn();
        echo "$name: no error\n";
    } catch (TypeError $e) {
        echo "$name: TypeError\n";
    }
}
