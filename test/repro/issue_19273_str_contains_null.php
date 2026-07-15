<?php
// Repro #19273 — str_contains/starts_with/ends_with null haystack under PHP_COMPILER_PROFILE=8.4
foreach (['str_contains', 'str_starts_with', 'str_ends_with'] as $f) {
    try {
        $f(null, 'x');
        echo "$f:OK\n";
    } catch (Throwable $e) {
        echo "$f: ".get_class($e)."\n";
    }
}
