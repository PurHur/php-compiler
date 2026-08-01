<?php
/**
 * Repro #26657 — range() step larger than span must throw ValueError (php-src array.c).
 */
foreach ([[0, 1, 2], [0.0, 1.0, 2.0], ['a', 'c', 5], [0, 2, 2], [0, 0, 5]] as $i => $args) {
    try {
        $r = range(...$args);
        echo "$i ok=".json_encode($r)."\n";
    } catch (Throwable $e) {
        echo "$i ".get_class($e).': '.$e->getMessage()."\n";
    }
}
