--TEST--
microtime Reflection return string|float (VM, issue #25967, basic_functions.stub.php)
--FILE--
<?php
$r = new ReflectionFunction('microtime');
echo 'microtime=', $r->hasReturnType() ? (string) $r->getReturnType() : 'none', "\n";
echo 'as_float=', gettype(microtime(true)), "\n";
echo 'as_string=', gettype(microtime(false)), "\n";
?>
--EXPECT--
microtime=string|float
as_float=double
as_string=string
