<?php
// Repro #22827 — string $search + array $replace must TypeError (php-src string.c).
try {
    var_export(str_replace('a', ['x', 'y'], 'a'));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    var_export(str_ireplace('A', ['x', 'y'], 'a'));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
echo str_replace(['a', 'b'], ['A', 'B'], 'ab'), "\n";
