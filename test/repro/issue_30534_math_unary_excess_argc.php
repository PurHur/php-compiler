<?php

/**
 * #30534 — unary math + pi() excess argc → ArgumentCountError (php-src math.c).
 */
error_reporting(E_ALL);

$cases = [
    static fn () => pi(1),
    static fn () => sqrt(4, 1),
    static fn () => sin(0, 1),
    static fn () => asinh(0, 1),
    static fn () => deg2rad(1, 2),
    static fn () => log10(10, 1),
];

foreach ($cases as $fn) {
    try {
        $fn();
        echo "NO_THROW\n";
    } catch (ArgumentCountError $e) {
        echo $e->getMessage(), "\n";
    } catch (Throwable $e) {
        echo get_class($e), ':', $e->getMessage(), "\n";
    }
}
