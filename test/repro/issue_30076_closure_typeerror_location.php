<?php
/** Repro #30076 — PHP 8.4 closure TypeError includes defining function/file + line. */
function outer() {
    $f = function (int $x): int { return $x; };
    try {
        $f(null);
    } catch (Throwable $e) {
        echo $e->getMessage(), PHP_EOL;
    }
    $g = function (): int { return null; };
    try {
        $g();
    } catch (Throwable $e) {
        echo $e->getMessage(), PHP_EOL;
    }
}
outer();
$h = fn (): int => null;
try {
    $h();
} catch (Throwable $e) {
    echo $e->getMessage(), PHP_EOL;
}
