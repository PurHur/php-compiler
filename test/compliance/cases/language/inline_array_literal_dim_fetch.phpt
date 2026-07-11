--TEST--
language inline array literal dim-fetch — isset/empty/offset (#16462, Zend/zend_compile.c)
--FILE--
<?php
var_dump((['a' => 1])['a']);
var_dump(isset(['a' => 1]['a']));
var_dump(empty(['a' => 1]['a']));
echo (['x' => 9])['x'];
?>
--EXPECT--
int(1)
bool(true)
bool(false)
9
