--TEST--
AOT: isset()/print on packed native array (#32556 leftover of #32475)
--FILE--
<?php
$a = [1];
var_dump(isset($a));
$empty = [];
var_dump(isset($empty));
print $a;
echo "\n";
--EXPECT--
bool(true)
bool(true)
Array
--EXPECT_EXIT--
0
