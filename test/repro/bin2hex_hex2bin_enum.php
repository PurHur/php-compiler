<?php
enum B: int { case One = 1; }
foreach (['bin2hex', 'hex2bin'] as $fn) {
    try {
        $fn(B::One);
        echo "{$fn} ok\n";
    } catch (Throwable $e) {
        echo $fn, ' ', $e::class, ': ', $e->getMessage(), "\n";
    }
}
