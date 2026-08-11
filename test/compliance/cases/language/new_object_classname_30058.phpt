--TEST--
language: new $object uses object's class (zend_execute.c ZEND_NEW / Z_OBJCE_P, #30058)
--FILE--
<?php
$a = new stdClass;
$b = new $a;
var_export($b instanceof stdClass);
echo "\n";

class UserNewObj {}
$u = new UserNewObj;
$v = new $u;
var_export($v instanceof UserNewObj);
echo "\n";

$c = new ArrayObject([]);
$d = new $c;
var_export($d instanceof ArrayObject);
echo "\n";

$s = 'stdClass';
$e = new $s;
var_export($e instanceof stdClass);
echo "\n";
?>
--EXPECT--
true
true
true
true
