--TEST--
getenv Reflection return array|string|false (VM, issue #26115, basic_functions.stub.php)
--FILE--
<?php
$r = new ReflectionFunction('getenv');
echo 'getenv=', $r->hasReturnType() ? (string) $r->getReturnType() : 'none', "\n";
$all = getenv();
echo 'noarg=', is_array($all) ? 'array' : gettype($all), "\n";
putenv('PHPC_GETENV_REFLECT=1');
$one = getenv('PHPC_GETENV_REFLECT');
echo 'named=', ($one === '1') ? '1' : '0', "\n";
putenv('PHPC_GETENV_REFLECT');
?>
--EXPECT--
getenv=array|string|false
noarg=array
named=1
