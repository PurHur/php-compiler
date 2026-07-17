<?php
declare(strict_types=1);
/**
 * Repro #19968 — session_id() must warn and refuse id change after headers sent.
 */

enum E: string {
    case A = 'sessid';
}

$warned = false;
set_error_handler(static function (int $errno, string $errstr) use (&$warned): bool {
    if (str_contains($errstr, 'Session ID cannot be changed after headers have already been sent')) {
        $warned = true;
    }

    return true;
});

try {
    session_id(E::A);
    echo "uncaught-enum\n";
} catch (TypeError $e) {
    echo "typeerror\n";
}

echo 'body';
$result = session_id('abc123');
restore_error_handler();
echo $warned ? "warned\n" : "no-warn\n";
echo false === $result ? "false\n" : "bad-result\n";
echo session_id() === '' ? "empty-id\n" : ('bad-id:' . session_id() . "\n");
