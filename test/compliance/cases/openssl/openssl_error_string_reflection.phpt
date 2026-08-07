--TEST--
openssl_error_string Reflection return string|false (VM, issue #28368, openssl.stub.php)
--FILE--
<?php
$r = new ReflectionFunction('openssl_error_string');
echo 'ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'none', "\n";
echo 'argc=', $r->getNumberOfParameters(), "\n";
$v = openssl_error_string();
echo 'runtime=', (false === $v || is_string($v)) ? 'ok' : gettype($v), "\n";
?>
--EXPECT--
ret=string|false
argc=0
runtime=ok
