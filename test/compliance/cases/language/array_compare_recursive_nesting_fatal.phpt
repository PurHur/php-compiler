--TEST--
Language: circular array == / === / <=> fatals Nesting level too deep (issue #28794, Zend/zend_hash.c)
--FILE--
<?php
ini_set('memory_limit', '64M');

$nested = [1, [2, [3]]];
$nested2 = [1, [2, [3]]];
echo ($nested == $nested2) ? "nested_ok\n" : "nested_fail\n";

$a = [];
$a[] = &$a;
echo ($a == $a) ? "same_ok\n" : "same_fail\n";

$b = [];
$b[] = &$b;
var_export($a == $b);
echo "\n";
?>
--EXPECTF--
nested_ok
same_ok
PHP Fatal error:  Nesting level too deep - recursive dependency? in %s on line %d
--EXPECT_EXIT--
255
