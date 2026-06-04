--TEST--
Stdlib: get_object_vars() omits uninitialized typed properties (JIT, #5398)
--FILE--
<?php
class C {
    public int $x;
}
$c = new C();
var_export(get_object_vars($c));
echo "\n";
--EXPECT--
array (
)
