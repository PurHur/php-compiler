--TEST--
Language: (clone $obj) with ['prop'] parenthesized operand (PHP 8.4, #10496)
--FILE--
<?php
declare(strict_types=1);

class C {
    public int $x = 1;
    public string $y = 'a';
}

$c = new C();
$d = (clone $c) with ['x' => 2, 'y' => 'b'];
var_export([$d->x, $d->y]);
--EXPECT--
array (
  0 => 2,
  1 => 'b',
)
