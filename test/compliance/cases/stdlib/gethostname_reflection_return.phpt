--TEST--
stdlib gethostname Reflection return string|false (#28000, basic_functions.stub.php)
--FILE--
<?php
$r = new ReflectionFunction('gethostname');
echo 'ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'none', "\n";
echo 'argc=', $r->getNumberOfParameters(), "\n";
$h = gethostname();
echo 'runtime=', (is_string($h) || $h === false) ? 'ok' : gettype($h), "\n";
?>
--EXPECT--
ret=string|false
argc=0
runtime=ok
