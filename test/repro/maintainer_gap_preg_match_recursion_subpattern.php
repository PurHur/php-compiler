<?php

declare(strict_types=1);

$warnings = [];
set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
    if (E_WARNING === $severity) {
        $warnings[] = $message;
    }

    return true;
});

preg_match('/(?R)/', 'x');
restore_error_handler();

if (6 !== preg_last_error()) {
    echo 'fail: preg_last_error expected 6, got '.preg_last_error()."\n";
    exit(1);
}

$expectedMsg = 'JIT stack limit exhausted';
if ($expectedMsg !== preg_last_error_msg()) {
    echo 'fail: preg_last_error_msg expected '.var_export($expectedMsg, true)
        .' got '.var_export(preg_last_error_msg(), true)."\n";
    exit(1);
}

if ([] !== $warnings) {
    echo 'fail: expected no warnings, got '.var_export($warnings, true)."\n";
    exit(1);
}

echo "ok\n";
