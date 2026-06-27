<?php

// Repro for #12666 — symlink(null, $target) must return false + E_WARNING (php-src filestat.c php_symlink).
try {
    $ok = @symlink(null, '/tmp/symlink_null_target');
    if (false !== $ok) {
        echo 'fail: symlink(null, ...) returned ', var_export($ok, true), ", expected false\n";
        exit(1);
    }
} catch (Throwable $e) {
    echo 'fail: symlink(null, ...) threw ', get_class($e), ': ', $e->getMessage(), "\n";
    exit(1);
}

echo "ok\n";
