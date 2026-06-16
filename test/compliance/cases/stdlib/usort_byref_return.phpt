--TEST--
stdlib usort() by-ref array survives return capture + read (#9075)
--FILE--
<?php
$a = [3, 1, 2];
$r = usort($a, fn ($x, $y) => $x <=> $y);
var_dump($r);
var_dump($a);
--EXPECT--
bool(true)
array(3) {
  [0]=>
  int(1)
  [1]=>
  int(2)
  [2]=>
  int(3)
}
