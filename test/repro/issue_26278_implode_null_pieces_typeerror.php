<?php

/**
 * #26278 — PROFILE≥8.4 implode/join(',', null) TypeError names arg #2 ($array)
 * (php-src ext/standard/string.c; dual-arg message from PHP 8.4 / PR #12683).
 */
error_reporting(E_ALL & ~E_DEPRECATED);

foreach (['implode', 'join'] as $f) {
    echo $f, '(",", null) => ';
    try {
        var_export($f(',', null));
    } catch (Throwable $e) {
        echo get_class($e), ':', $e->getMessage();
    }
    echo PHP_EOL;
}

echo 'implode(",", ["a","b"]) => ';
try {
    var_export(implode(',', ['a', 'b']));
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage();
}
echo PHP_EOL;
