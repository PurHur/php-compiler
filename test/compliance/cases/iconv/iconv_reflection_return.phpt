--TEST--
iconv Reflection return string|false (VM, issue #28424)
--FILE--
<?php
$r = new ReflectionFunction('iconv');
echo 'return=', $r->hasReturnType() ? (string) $r->getReturnType() : 'none', "\n";
echo 'ok=', var_export(iconv('UTF-8', 'UTF-8', 'café'), true), "\n";
echo 'fail=', var_export(@iconv('UTF-8', 'ASCII', 'café'), true), "\n";
?>
--EXPECT--
return=string|false
ok='café'
fail=false
