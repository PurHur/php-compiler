<?php

declare(strict_types=1);

/**
 * Bootstrap AOT lint: JIT Helper VALUE paired with native long loose == / !=.
 */

class Holder
{
    /** @var mixed */
    public $n = 42;

    /** @var mixed */
    public $other = 'x';
}

$h = new Holder();
$eq = $h->n == 42;
$neZero = $h->n != 0;
$neOther = $h->other != 0;
$revEq = 42 == $h->n;

echo (string) ((int) $eq + (int) $neZero + (int) $neOther + (int) $revEq);
