<?php

declare(strict_types=1);

$ok = true;
foreach ([
    'strnatcmp' => fn() => strnatcmp(null, '1'),
    'strcoll' => fn() => strcoll(null, 'a'),
] as $name => $fn) {
    try {
        $fn();
        echo "$name: no error\n";
        $ok = false;
    } catch (TypeError) {
        // expected
    }
}

if (!$ok) {
    exit(1);
}

echo "ok\n";
