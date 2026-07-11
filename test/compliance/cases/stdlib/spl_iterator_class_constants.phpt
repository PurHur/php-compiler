--TEST--
stdlib SPL iterator class constants — defined() + ReflectionClass::getConstants() (#13134, ext/spl/spl_iterators.c)
--FILE--
<?php
var_export(defined('RecursiveIteratorIterator::LEAVES_ONLY'));
echo "\n";
var_export(constant('RecursiveIteratorIterator::LEAVES_ONLY'));
echo "\n";
var_export(defined('FilesystemIterator::SKIP_DOTS'));
echo "\n";
var_export(constant('FilesystemIterator::SKIP_DOTS'));
echo "\n";
$keys = array_keys((new ReflectionClass('RecursiveIteratorIterator'))->getConstants());
sort($keys);
var_export($keys);
echo "\n";
?>
--EXPECT--
true
0
true
4096
array (
  0 => 'CATCH_GET_CHILD',
  1 => 'CHILD_FIRST',
  2 => 'LEAVES_ONLY',
  3 => 'SELF_FIRST',
)
