--TEST--
Language: isset()/print on packed array match Zend (#32556 leftover of #32475)
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
