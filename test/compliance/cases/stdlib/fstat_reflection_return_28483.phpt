--TEST--
stdlib fstat Reflection return array|false (#28483, file.stub.php)
--FILE--
<?php
$r = new ReflectionFunction('fstat');
echo $r->hasReturnType() ? (string) $r->getReturnType() : '-', PHP_EOL;
?>
--EXPECT--
array|false
