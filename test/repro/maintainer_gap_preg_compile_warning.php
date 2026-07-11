<?php

declare(strict_types=1);

$warnings = [];
set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
    if (E_WARNING === $severity) {
        $warnings[] = $message;
    }

    return true;
});

preg_match('/(/', 'x');
restore_error_handler();

if (1 !== preg_last_error()) {
    echo 'fail: preg_last_error expected 1, got '.preg_last_error()."\n";
    exit(1);
}

$expected = 'preg_match(): Compilation failed: missing closing parenthesis at offset 1';
if ([] === $warnings || $warnings[0] !== $expected) {
    echo 'fail: warning expected '.var_export($expected, true).' got '.var_export($warnings, true)."\n";
    exit(1);
}

echo "ok\n";
