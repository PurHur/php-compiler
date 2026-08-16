--TEST--
getcwd Reflection return string|false (VM, issue #28174, basic_functions.stub.php)
--FILE--
<?php
$r = new ReflectionFunction('getcwd');
echo 'getcwd=', $r->hasReturnType() ? (string) $r->getReturnType() : 'none', "\n";
$cwd = getcwd();
echo 'cwd_ok=', is_string($cwd) ? '1' : '0', "\n";
?>
--EXPECT--
getcwd=string|false
cwd_ok=1
