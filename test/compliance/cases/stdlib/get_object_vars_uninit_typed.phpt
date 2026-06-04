--TEST--
Stdlib: get_object_vars() omits uninitialized typed properties (#5398, ext/standard/var.c)
--FILE--
<?php
class C {
    public int $x;
    public string $y = 'set';
}
$c = new C();
var_export(get_object_vars($c));
echo "\n";
$c->x = 0;
var_export(get_object_vars($c));
echo "\n";

class Nullable {
    public ?int $n;
}
$n = new Nullable();
var_export(get_object_vars($n));
echo "\n";
--EXPECT--
array (
  'y' => 'set',
)
array (
  'x' => 0,
  'y' => 'set',
)
array (
)
