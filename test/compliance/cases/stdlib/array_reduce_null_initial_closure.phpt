--TEST--
stdlib array_reduce() inline Closure + null $initial (issue #23571)
--FILE--
<?php
$viaVar = array_reduce([1, 2], static function ($c, $v) {
    return ($c ?? 0) + $v;
}, $init = null);
echo "viaVar=$viaVar\n";

echo 'literal=', array_reduce([1, 2], static function ($c, $v) {
    return ($c ?? 0) + $v;
}, null), "\n";

echo 'named=', array_reduce([1, 2], static fn($c, $v) => ($c ?? 0) + $v, initial: null), "\n";

echo 'arrow=', array_reduce([1, 2], static fn($c, $v) => ($c ?? 0) + $v, null), "\n";

echo 'zero=', array_reduce([1, 2], static fn($c, $v) => ($c ?? 0) + $v, 0), "\n";

echo 'false=', array_reduce([1, 2], static fn($c, $v) => ($c ?? 0) + $v, false), "\n";
--EXPECT--
viaVar=3
literal=3
named=3
arrow=3
zero=3
false=3
