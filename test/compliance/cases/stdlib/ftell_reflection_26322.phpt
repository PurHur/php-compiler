--TEST--
ftell Reflection return int|false (#26322, file.stub.php)
--FILE--
<?php
$r = new ReflectionFunction('ftell');
echo 'ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'NONE', "\n";
?>
--EXPECT--
ret=int|false
