--TEST--
stdlib net_get_interfaces Reflection return array|false (#27776, basic_functions.stub.php)
--FILE--
<?php
$r = new ReflectionFunction('net_get_interfaces');
echo $r->hasReturnType() ? (string) $r->getReturnType() : '-', PHP_EOL;
?>
--EXPECT--
array|false
