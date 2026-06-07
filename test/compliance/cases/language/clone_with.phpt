--TEST--
Language: clone with property overrides (PHP 8.3, #4513)
--FILE--
<?php
class C {
    public int $x = 1;
    public string $y = 'a';
}

$c = new C();
$d = clone $c with { x: 2, y: 'b' };
var_export([$d->x, $d->y]);
--EXPECT--
array (
  0 => 2,
  1 => 'b',
)
