<?php

declare(strict_types=1);

// Issue #14642 — readlink(null) warns and returns false (ext/standard/link.c).
$errors = 0;
$warns = [];
set_error_handler(static function (int $errno, string $msg) use (&$warns): bool {
    $warns[] = $msg;

    return true;
});
try {
    $r = readlink(null);
    if (false !== $r) {
        echo "fail: readlink(null) expected false\n";
        ++$errors;
    }
    foreach ($warns as $w) {
        if (!str_contains($w, 'readlink():')) {
            echo "fail: unexpected warning: {$w}\n";
            ++$errors;
        }
    }
    if ([] === $warns) {
        echo "fail: readlink(null) expected warning\n";
        ++$errors;
    }
} catch (Throwable $e) {
    echo 'fail: readlink(null) threw '.get_class($e)."\n";
    ++$errors;
}
restore_error_handler();
echo 0 === $errors ? "ok\n" : "fail\n";
exit($errors > 0 ? 1 : 0);
