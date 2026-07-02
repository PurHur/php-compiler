<?php

declare(strict_types=1);

$warnings = [];
set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
    if (E_WARNING === $severity) {
        $warnings[] = $message;
    }

    return true;
});

switch (1) {
    case 1:
        for (;;) {
            continue 2;
        }
}

restore_error_handler();

$expected = '"continue 2" targeting switch is equivalent to "break 2"';
if ([] === $warnings || $warnings[0] !== $expected) {
    echo 'fail: warning expected '.var_export($expected, true).' got '.var_export($warnings, true)."\n";
    exit(1);
}

echo "ok\n";
