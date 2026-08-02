<?php
/**
 * #26824 — user-script AOT array_find family Closures (php-src-strict).
 * php-src: ext/standard/array.c
 */
$a = [10, 20, 30];
echo array_find($a, fn ($x) => $x > 15), "\n";
echo array_find_key($a, fn ($x) => $x > 15), "\n";
echo array_any($a, fn ($x) => $x > 25) ? "any\n" : "noany\n";
echo array_all($a, fn ($x) => $x > 5) ? "all\n" : "noall\n";
