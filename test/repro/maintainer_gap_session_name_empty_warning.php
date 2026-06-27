<?php

declare(strict_types=1);

// Repro for #12563 — session_name('') must Warning and leave PHPSESSID unchanged.
$warned = false;
set_error_handler(static function (int $errno, string $errstr) use (&$warned): bool {
    if (str_contains($errstr, 'session.name "" cannot be numeric or empty')) {
        $warned = true;
    }

    return true;
});
session_name('');
restore_error_handler();
$name = session_name();
if ($warned && 'PHPSESSID' === $name) {
    echo "ok\n";
} else {
    echo 'fail: expected session_name() empty-string Warning'."\n";
}
