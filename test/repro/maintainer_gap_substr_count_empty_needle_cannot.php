<?php

/**
 * #30522 — substr_count() empty / null-coerced needle ValueError uses Zend "cannot be empty"
 * (php-src ext/standard/string.c PHP_FUNCTION(substr_count); sibling of #30505).
 */
$expected = 'substr_count(): Argument #2 ($needle) cannot be empty';

try {
    substr_count('aa', '');
    fwrite(STDERR, "fail: substr_count empty needle expected ValueError\n");
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
    substr_count('aa', null);
    fwrite(STDERR, "fail: substr_count(null) expected ValueError\n");
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
