<?php

error_reporting(E_ALL);
ini_set('display_errors', '1');

$dep = 0;
set_error_handler(static function (int $errno) use (&$dep): bool {
    if (E_DEPRECATED === $errno) {
        ++$dep;
    }

    return true;
});

file_exists(null);
is_writable(null);
@unlink(null);

restore_error_handler();

if (3 !== $dep) {
    fwrite(STDERR, "expected 3 E_DEPRECATED, got $dep\n");
    exit(1);
}

echo "ok\n";
