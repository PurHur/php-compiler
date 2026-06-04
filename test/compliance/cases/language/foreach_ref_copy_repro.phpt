--TEST--
Language: foreach by-ref then by-value copies referenced slots (Zend zend_execute.c, #5419)
--FILE--
<?php
$arr = [1, 2, 3];
foreach ($arr as &$v) {
    $v *= 2;
}
foreach ($arr as $v) {
}
var_export($arr);
echo "\n";
--EXPECT--
array (
  0 => 2,
  1 => 4,
  2 => 4,
)
