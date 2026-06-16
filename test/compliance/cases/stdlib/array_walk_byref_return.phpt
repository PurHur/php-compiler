--TEST--
stdlib array_walk() by-ref array survives return capture + read (#9074)
--FILE--
<?php
$a = [1, 2];
$r = array_walk($a, function (&$v) {
    $v *= 2;
});
var_dump($r);
var_dump($a);
--EXPECT--
bool(true)
array(2) {
  [0]=>
  int(2)
  [1]=>
  int(4)
}
