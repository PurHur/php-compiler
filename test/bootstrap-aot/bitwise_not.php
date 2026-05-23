<?php

declare(strict_types=1);

/**
 * Bootstrap AOT lint: JIT unary TYPE_BITWISE_NOT on native long/bool/value operands.
 */

$a = ~7;
$b = ~0;
$c = ~1; // PHP 8.2+ rejects ~true; use int operand for bool-like not

class Holder
{
    /** @var mixed */
    public $n = 7;
}

$h = new Holder();
$d = ~$h->n;

echo (string) ($a + $b + $c + $d);
