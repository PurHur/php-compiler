--TEST--
foreach by-reference: unset($v) must not destroy last element (#4997)
--FILE--
<?php
$a = [1, 2, 3];
foreach ($a as &$v) {
    $v *= 10;
}
unset($v);
var_export($a);
echo "\n";
$c = [1, 2, 3];
foreach ($c as $k => &$v) {
    if ($k === 0) {
        $c[] = 4;
    }
}
unset($v);
var_export($c);
echo "\n";
--EXPECT--
array (
  0 => 10,
  1 => 20,
  2 => 30,
)
array (
  0 => 1,
  1 => 2,
  2 => 3,
  3 => 4,
)
