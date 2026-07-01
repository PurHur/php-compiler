<?php

declare(strict_types=1);

enum E: int
{
    case A = 1;
    case B = 2;
}

$expected = <<<'OUT'
array (
  0 => 
  \E::A,
  1 => 
  \E::B,
)
OUT;

$actual = var_export([E::A, E::B], true);
if ($expected !== $actual) {
    echo "EXPECTED:\n{$expected}\n";
    echo "ACTUAL:\n{$actual}\n";
    exit(1);
}

echo "OK\n";
