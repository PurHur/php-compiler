<?php

// Repro for #12653 — touch(null)/touch('') must return false like Zend (php-src filestat.c php_touch).
$fail = 0;
foreach ([null, ''] as $path) {
    $label = null === $path ? 'null' : "''";
    try {
        $ok = touch($path);
        if (false !== $ok) {
            echo 'fail: touch(', $label, ') returned ', var_export($ok, true), ", expected false\n";
            ++$fail;
        }
    } catch (Throwable $e) {
        echo 'fail: touch(', $label, ') threw ', get_class($e), ': ', $e->getMessage(), "\n";
        ++$fail;
    }
}
if (0 === $fail) {
    echo "ok\n";
}
