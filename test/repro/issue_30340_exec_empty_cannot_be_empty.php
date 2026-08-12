<?php

/**
 * #30340 — exec/system/passthru/shell_exec empty command ValueError uses Zend "cannot be empty"
 * (php-src ext/standard/exec.c). Soft-null Deprecated then ValueError (no strict_types).
 */
error_reporting(E_ALL);

foreach (['exec', 'system', 'passthru', 'shell_exec'] as $fn) {
    $expected = $fn.'(): Argument #1 ($command) cannot be empty';
    try {
        if ('passthru' === $fn) {
            $fn('');
        } else {
            $fn('');
        }
        fwrite(STDERR, "fail: $fn(\"\") expected ValueError\n");
        exit(1);
    } catch (ValueError $e) {
        if ($expected !== $e->getMessage()) {
            fwrite(STDERR, "fail: $fn empty got {$e->getMessage()}\n");
            exit(1);
        }
        echo $fn, ':empty:', $e->getMessage(), "\n";
    }

    try {
        if ('passthru' === $fn) {
            $fn(null);
        } else {
            $fn(null);
        }
        fwrite(STDERR, "fail: $fn(null) expected ValueError\n");
        exit(1);
    } catch (ValueError $e) {
        if ($expected !== $e->getMessage()) {
            fwrite(STDERR, "fail: $fn null got {$e->getMessage()}\n");
            exit(1);
        }
        echo $fn, ':null:', $e->getMessage(), "\n";
    }
}

echo "ok\n";
