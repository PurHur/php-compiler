<?php

declare(strict_types=1);

/**
 * Bootstrap AOT lint: object pointer identity (=== / !==) for Compiler Operand paths (#1056).
 */

class Box
{
}

$a = new Box();
$b = new Box();
$c = $a;

$same = $a === $c;
$diff = $a === $b;
$notSame = $a !== $b;

echo (string) ((int) $same + (int) !$diff + (int) $notSame);
