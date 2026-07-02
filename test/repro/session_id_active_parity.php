<?php

declare(strict_types=1);

$warnings = [];
set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
    if (E_WARNING === $severity) {
        $warnings[] = $message;
    }

    return true;
});

session_start();
$before = session_id();
$changed = session_id('customid123');
$after = session_id();
restore_error_handler();

if (false !== $changed) {
    echo 'fail: session_id(custom) expected false, got '.var_export($changed, true)."\n";
    exit(1);
}
if ($after !== $before) {
    echo 'fail: after='.var_export($after, true).' expected unchanged '.var_export($before, true)."\n";
    exit(1);
}

$expected = 'session_id(): Session ID cannot be changed when a session is active';
if ([] === $warnings || $warnings[0] !== $expected) {
    echo 'fail: warning expected '.var_export($expected, true).' got '.var_export($warnings, true)."\n";
    exit(1);
}

echo "ok\n";
