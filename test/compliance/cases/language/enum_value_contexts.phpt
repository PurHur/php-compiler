--TEST--
Language: enum case objects preserved in list destruct, spread, ref read, foreach by-ref (#9342, zend_enum.c)
--FILE--
<?php
enum E: int { case A = 1; case B = 2; }

$arr = [E::A];
$ref = &$arr[0];
echo "ref: ";
var_export($ref);
echo " instanceof ";
var_export($ref instanceof E);
echo "\n";

echo "spread: ";
$spread = [...([E::A, E::B])];
var_export($spread);
echo "\n";
var_export($spread[0] instanceof E);
echo "\n";

[$a, $b] = [E::A, E::B];
echo "list: ";
var_export([$a, $b]);
echo "\n";
var_export([$a instanceof E, $b instanceof E]);
echo "\n";

$arr2 = [E::A, E::B];
foreach ($arr2 as &$v) {
    $v = E::B;
}
unset($v);
echo "foreach_byref: ";
var_export($arr2);
echo "\n";
var_export($arr2[0] instanceof E);
echo "\n";
--EXPECT--
ref: \E::A instanceof true
spread: array (
  0 => \E::A,
  1 => \E::B,
)
true
list: array (
  0 => \E::A,
  1 => \E::B,
)
array (
  0 => true,
  1 => true,
)
foreach_byref: array (
  0 => \E::B,
  1 => \E::B,
)
true
