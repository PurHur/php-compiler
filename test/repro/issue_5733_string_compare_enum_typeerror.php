<?php
// Issue #5733 — strcmp-family enum case operands must TypeError (ext/standard/string.c)
enum S: string { case X = 'a'; }
enum U { case Y; }

foreach ([
    'strncmp' => [S::X, 'b', 1],
    'strcasecmp' => [S::X, 'b'],
    'strncasecmp' => [S::X, 'b', 1],
    'strnatcmp' => [U::Y, 'b'],
    'strnatcasecmp' => [U::Y, 'b'],
] as $fn => $args) {
    try {
        var_export($fn(...$args));
        echo " {$fn}\n";
    } catch (Throwable $e) {
        echo $fn, ' ', $e::class, "\n";
    }
}
