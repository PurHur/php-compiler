--TEST--
getrusage Reflection return array|false (VM, issue #28841, basic_functions.stub.php)
--FILE--
<?php
$r = new ReflectionFunction('getrusage');
echo 'getrusage=', $r->hasReturnType() ? (string) $r->getReturnType() : 'none', "\n";
$u = getrusage();
echo 'getrusage_runtime=', (false === $u || is_array($u)) ? 'ok' : gettype($u), "\n";
?>
--EXPECT--
getrusage=array|false
getrusage_runtime=ok
