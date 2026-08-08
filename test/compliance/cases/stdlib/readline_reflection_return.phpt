--TEST--
readline Reflection return string|false (#28342)
--FILE--
<?php
$r = new ReflectionFunction('readline');
echo $r->hasReturnType() ? (string) $r->getReturnType() : '-', PHP_EOL;
--EXPECT--
string|false
