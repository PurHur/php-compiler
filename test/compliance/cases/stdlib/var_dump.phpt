--TEST--
stdlib var_dump() — array with keys and types (#3133)
--FILE--
<?php
$a = ['k' => 1, 'nested' => ['x' => 2]];
var_dump($a);
--EXPECT--
array(2) {
["k"]=>
int(1)
["nested"]=>
array(1) {
["x"]=>
int(2)
}
}
