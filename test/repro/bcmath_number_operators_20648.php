<?php
/** Repro #20648 / #21266 — BcMath\Number arithmetic operators (php-src bcmath_number_do_operation). */
$a = new BcMath\Number('1.5');
$b = new BcMath\Number('2.5');
echo 'op+', (string) ($a + $b), "\n";
echo 'add=', (string) $a->add($b), "\n";
// Operators must work inside try/catch the same as at top level (#21266).
try {
    echo 'try+', (string) ($a + $b), "\n";
} catch (Throwable $e) {
    echo 'tryEX=', $e->getMessage(), "\n";
}
try {
    throw new Exception('unrelated');
} catch (Throwable $e) {
    echo 'caught=', $e->getMessage(), "\n";
}
echo 'after+', (string) ($a + $b), "\n";
