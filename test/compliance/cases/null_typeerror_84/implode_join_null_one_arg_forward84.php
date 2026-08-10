<?php

error_reporting(E_ALL);
ini_set('display_errors', '1');

set_error_handler(static function (int $severity, string $message): bool {
    if (E_DEPRECATED === $severity) {
        echo 'Deprecated: ', $message, "\n";

        return true;
    }

    return false;
});

foreach (['implode', 'join'] as $fn) {
    try {
        $fn(null);
        echo $fn, "(null) => uncaught\n";
    } catch (Throwable $t) {
        echo $fn, '(null) => ', get_class($t), ': ', $t->getMessage(), "\n";
    }
}

try {
    $joined = implode(null, ['a', 'b']);
    echo 'implode(null, ["a","b"]) => ', var_export($joined, true), "\n";
} catch (Throwable $t) {
    echo 'implode(null, ["a","b"]) => ', get_class($t), ': ', $t->getMessage(), "\n";
}
