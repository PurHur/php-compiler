--TEST--
ob_get_clean Reflection return string|false (VM, issue #25593, basic_functions.stub.php)
--FILE--
<?php
$r = new ReflectionFunction('ob_get_clean');
echo 'ob_get_clean=', $r->hasReturnType() ? (string) $r->getReturnType() : 'none', "\n";
$empty = ob_get_clean();
echo 'empty=', var_export($empty, true), "\n";
ob_start();
echo 'payload';
$got = ob_get_clean();
echo 'got=', var_export($got, true), "\n";
?>
--EXPECT--
ob_get_clean=string|false
empty=false
got='payload'
