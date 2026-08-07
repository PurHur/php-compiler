--TEST--
session_encode Reflection return string|false (#27726, session.stub.php)
--FILE--
<?php
$r = new ReflectionFunction('session_encode');
echo 'return=', $r->hasReturnType() ? (string) $r->getReturnType() : '(none)', "\n";
echo 'argc=', $r->getNumberOfParameters(), "\n";
?>
--EXPECT--
return=string|false
argc=0
