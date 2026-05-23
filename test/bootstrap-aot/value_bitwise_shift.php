<?php

declare(strict_types=1);

/**
 * Bootstrap AOT lint: JIT Helper VALUE paired with native long/bool bitwise/shift ops.
 * Mirrors lib/JIT/Variable.php type_pair() and TYPE_PAIR_* constant folding.
 */

function type_pair(int $left, int $right): int
{
    return ($left << 16) | $right;
}

class Holder
{
    /** @var mixed */
    public $n = 1;

    /** @var mixed */
    public $flag = true;
}

$h = new Holder();
$pair = type_pair(1, 1);
$shifted = $h->n << 4;
$masked = $h->n & 15;
$combined = $shifted | ($h->flag ? 1 : 0);

echo (string) ($pair + $masked + $combined);
