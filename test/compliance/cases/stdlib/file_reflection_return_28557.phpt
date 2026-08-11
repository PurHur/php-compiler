--TEST--
stdlib file Reflection return array|false (#28557, file.stub.php)
--FILE--
<?php
$r = new ReflectionFunction('file');
echo $r->hasReturnType() ? (string) $r->getReturnType() : '-', PHP_EOL;
?>
--EXPECT--
array|false
