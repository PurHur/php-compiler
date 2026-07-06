--TEST--
stdlib class_uses_recursive() — registered on PHP_COMPILER_PROFILE=8.4 (#16708)
--SKIPIF--
<?php
if (getenv('PHP_COMPILER_PROFILE') !== '8.4') {
    echo 'skip forward profile only';
}
?>
--FILE--
<?php
echo function_exists('class_uses_recursive') ? '1' : '0';
trait A {}
trait B { use A; }
class C { use B; }
$recursive = class_uses_recursive(C::class);
echo isset($recursive['A']) ? '1' : '0';
echo isset($recursive['B']) ? '1' : '0';
echo "\n";
--EXPECT--
111
