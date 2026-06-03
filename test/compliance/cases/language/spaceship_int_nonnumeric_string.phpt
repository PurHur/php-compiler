--TEST--
Language: spaceship (<=>) int vs non-numeric string — Zend compare_function (#4681)
--FILE--
<?php
var_dump(1 <=> 'b');

$a = [1, 'b'];
uasort($a, fn ($x, $y) => $x <=> $y);
var_dump($a);
--EXPECT--
int(-1)
array(2) {
  [0]=>
  int(1)
  [1]=>
  string(1) "b"
}
