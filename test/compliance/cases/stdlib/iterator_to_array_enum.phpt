--TEST--
iterator_to_array() preserves enum cases from ArrayIterator after prior iterator use (#5636)
--FILE--
<?php
$it = new ArrayIterator([1, 2, 3]);
$out = [];
foreach ($it as $v) {
    $out[] = $v;
}
echo implode(',', $out), "\n";
echo $it->count(), "\n";

enum E: int { case A = 1; case B = 2; }
$enumIt = new ArrayIterator([E::A, E::B]);
var_export(iterator_to_array($enumIt));
echo "\n";
--EXPECT--
1,2,3
3
array (
  0 => 
  \E::A,
  1 => 
  \E::B,
)
