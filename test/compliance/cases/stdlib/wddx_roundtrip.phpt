--TEST--
stdlib wddx_serialize_value/wddx_deserialize round-trip (#6327, ext/wddx/wddx.c)
--SKIPIF--
<?php
if (!\PHPCompiler\CompilerVersion::supportsWddx()) {
    die('skip wddx withheld on reference profile (#6327)');
}
--FILE--
<?php
declare(strict_types=1);

$x = ['a' => 1, 'b' => 'two', 'c' => [10, 20]];
$round = wddx_deserialize(wddx_serialize_value($x));
echo is_array($round) ? '1' : '0';
echo $round === $x ? '1' : '0';
echo wddx_deserialize(wddx_serialize_value(42)) === 42 ? '1' : '0';
echo wddx_deserialize('<broken') === null ? '1' : '0';
echo function_exists('wddx_serialize_value') ? '1' : '0';
echo function_exists('wddx_deserialize') ? '1' : '0';
echo extension_loaded('wddx') ? '1' : '0';
echo "\n";
--EXPECT--
1111111
