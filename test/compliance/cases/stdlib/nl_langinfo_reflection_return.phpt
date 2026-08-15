--TEST--
nl_langinfo Reflection return string|false (#28334)
--FILE--
<?php
$r = new ReflectionFunction('nl_langinfo');
echo $r->hasReturnType() ? (string) $r->getReturnType() : '-', PHP_EOL;
--EXPECT--
string|false
