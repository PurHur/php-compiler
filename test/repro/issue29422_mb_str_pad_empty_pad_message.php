<?php

declare(strict_types=1);

/**
 * #29422 — mb_str_pad() empty $pad_string ValueError must match Zend: "must not be empty"
 * (php-src ext/mbstring/mbstring.c PHP_FUNCTION(mb_str_pad)).
 *
 * Requires PHP_COMPILER_PROFILE=8.4 (mb_str_pad withheld on 8.4.0-dev reference).
 */
$expected = 'mb_str_pad(): Argument #3 ($pad_string) must not be empty';

try {
    mb_str_pad('a', 5, '');
    fwrite(STDERR, "fail: mb_str_pad(..., '') expected ValueError\n");
    exit(1);
} catch (ValueError $e) {
    if ($expected !== $e->getMessage()) {
        fwrite(STDERR, "fail: empty got {$e->getMessage()}\n");
        exit(1);
    }
    echo 'empty:', $e->getMessage(), "\n";
}

$seen = [];
set_error_handler(static function (int $no, string $str) use (&$seen): bool {
    if (E_DEPRECATED === $no) {
        $seen[] = $str;
    }

    return true;
});
try {
    mb_str_pad('a', 3, null);
    fwrite(STDERR, "fail: mb_str_pad(..., null) expected ValueError after DEP\n");
    exit(1);
} catch (ValueError $e) {
    if ($expected !== $e->getMessage()) {
        fwrite(STDERR, "fail: null got {$e->getMessage()}\n");
        exit(1);
    }
    if (0 === count($seen)) {
        fwrite(STDERR, "fail: null pad_string expected E_DEPRECATED\n");
        exit(1);
    }
    echo 'null:', $e->getMessage(), "\n";
}
restore_error_handler();

echo "ok\n";
