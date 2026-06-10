<?php
// Issue #6018 — dechex/decbin/decoct enum case operands must TypeError (ext/standard/math.c).
enum E: int { case A = 10; }
$case = E::A;
foreach (['dechex', 'decbin', 'decoct'] as $fn) {
    try {
        $fn($case);
        echo "$fn: no error\n";
    } catch (Throwable $e) {
        echo "$fn: ", $e::class, ': ', $e->getMessage(), "\n";
    }
}
