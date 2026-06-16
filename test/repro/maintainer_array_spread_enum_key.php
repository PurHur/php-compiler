<?php
// Maintainer repro for #9014 — array spread/union with enum case keys (zend_hash.c).
declare(strict_types=1);

enum E: int { case A = 1; case B = 2; }

try {
    var_export([...[E::A => 2]]);
    echo "spread-fail\n";
} catch (Throwable $e) {
    echo 'spread: ', get_class($e), ': ', $e->getMessage(), "\n";
}

try {
    var_export([E::A => 1] + [E::A => 2]);
    echo "union-fail\n";
} catch (Throwable $e) {
    echo 'union: ', get_class($e), ': ', $e->getMessage(), "\n";
}
