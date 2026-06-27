<?php

// Repro for #12651 — opendir(null/empty) must return false + warning like Zend (php-src dir.c php_opendir).
$fail = 0;
foreach ([null, ''] as $path) {
    $label = null === $path ? 'null' : "''";
    try {
        $h = opendir($path);
        if (false !== $h) {
            echo 'fail: opendir(', $label, ') returned ', var_export($h, true), ", expected false\n";
            ++$fail;
        }
    } catch (Throwable $e) {
        echo 'fail: opendir(', $label, ') threw ', get_class($e), ': ', $e->getMessage(), "\n";
        ++$fail;
    }
}
if (0 === $fail) {
    echo "ok\n";
}
