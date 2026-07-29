<?php

/**
 * #24661 — trailing incomplete % in sprintf/printf/vsprintf/fprintf.
 * php-src: ext/standard/formatted_print.c
 */
$h = fopen('php://memory', 'w+');
foreach ([
    fn () => sprintf('%'),
    fn () => sprintf('%', 1),
    fn () => vsprintf('%', []),
    fn () => vsprintf('%', [1]),
    fn () => printf('%'),
    fn () => fprintf($h, '%'),
    fn () => fprintf($h, '%', 1),
] as $i => $fn) {
    try {
        $fn();
        echo "ok{$i}\n";
    } catch (Throwable $e) {
        echo get_class($e), ':', $e->getMessage(), "\n";
    }
}
