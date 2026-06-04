--TEST--
unset($GLOBALS['key']) removes key from $GLOBALS table (#5868)
--FILE--
<?php
$GLOBALS['test_key'] = 1;
unset($GLOBALS['test_key']);
var_export(array_key_exists('test_key', $GLOBALS));
echo "\n";
var_export(isset($GLOBALS['test_key']));
echo "\n";
--EXPECT--
false
false
