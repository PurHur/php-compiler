<?php
/**
 * Repro for #5883 — trig/hyperbolic math builtins must TypeError on enum case operands.
 *
 * @see ext/standard/math.c (php-src)
 */
enum E: int { case A = 1; }
foreach (['sin', 'cos', 'deg2rad', 'fmod'] as $fn) {
    try {
        $fn === 'fmod' ? $fn(E::A, 2.0) : $fn(E::A);
        echo "$fn: ok\n";
    } catch (TypeError $e) {
        echo "$fn: TypeError\n";
    } catch (Throwable $e) {
        echo "$fn: ", get_class($e), "\n";
    }
}
