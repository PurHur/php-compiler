<?php

/**
 * #30505 — explode() empty / null-coerced separator ValueError uses Zend "cannot be empty"
 * (php-src ext/standard/string.c PHP_FUNCTION(explode)).
 */
$expected = 'explode(): Argument #1 ($separator) cannot be empty';

try {
    explode('', 'a,b');
    fwrite(STDERR, "fail: explode('') expected ValueError\n");
    exit(1);
} catch (ValueError $e) {
    if ($expected !== $e->getMessage()) {
        fwrite(STDERR, "fail empty: got {$e->getMessage()}\n");
        exit(1);
    }
    echo 'empty:', $e->getMessage(), "\n";
}

$dep = false;
set_error_handler(static function (int $no, string $str) use (&$dep): bool {
    if (E_DEPRECATED === $no) {
        $dep = true;
        echo 'dep:', $str, "\n";

        return true;
    }

    return false;
});
try {
    explode(null, 'a,b');
    fwrite(STDERR, "fail: explode(null) expected ValueError\n");
    exit(1);
} catch (ValueError $e) {
    if (!$dep) {
        fwrite(STDERR, "fail: expected Deprecated before ValueError for null\n");
        exit(1);
    }
    if ($expected !== $e->getMessage()) {
        fwrite(STDERR, "fail null: got {$e->getMessage()}\n");
        exit(1);
    }
    echo 'null:', $e->getMessage(), "\n";
}
restore_error_handler();

echo "ok\n";
