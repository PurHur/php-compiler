--TEST--
symlink Reflection return bool (#26323, link.stub.php)
--FILE--
<?php
$r = new ReflectionFunction('symlink');
echo 'ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'NONE', "\n";
?>
--EXPECT--
ret=bool
