<?php

declare(strict_types=1);

// #18355 — strcmp family null operands TypeError under declare(strict_types=1) (ext/standard/string.c)

$checks = [
    'strcasecmp' => 'string2',
    'strnatcmp' => 'string2',
    'strnatcasecmp' => 'string2',
    'strcoll' => 'string2',
];

foreach ($checks as $fn => $param) {
    try {
        $fn('a', null);
        fwrite(STDERR, "{$fn}: expected TypeError\n");
        exit(1);
    } catch (TypeError $e) {
        $expected = sprintf('%s(): Argument #2 ($%s) must be of type string, null given', $fn, $param);
        if ($e->getMessage() !== $expected) {
            fwrite(STDERR, "{$fn}: unexpected message: {$e->getMessage()}\n");
            exit(1);
        }
        echo "{$fn}: ok\n";
    }
}

echo "ok\n";
