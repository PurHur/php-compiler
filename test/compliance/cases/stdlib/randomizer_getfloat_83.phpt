--TEST--
stdlib Random\Randomizer getFloat()/nextFloat() on forward profile (#17292, ext/random/randomizer.c)
--SKIPIF--
<?php
if (!getenv('PHP_COMPILER_PROFILE') || version_compare(getenv('PHP_COMPILER_PROFILE'), '8.3', '<')) {
    die('skip requires PHP_COMPILER_PROFILE >= 8.3');
}
?>
--FILE--
<?php
declare(strict_types=1);

var_export(method_exists(Random\Randomizer::class, 'getFloat'));
echo "\n";
var_export(method_exists(Random\Randomizer::class, 'nextFloat'));
echo "\n";
var_export(enum_exists('Random\\IntervalBoundary', false));
echo "\n";

$engine = new Random\Engine\Mt19937(42);
$randomizer = new Random\Randomizer($engine);
$next = $randomizer->nextFloat();
var_export($next >= 0.0 && $next < 1.0);
echo "\n";
$got = $randomizer->getFloat(0.0, 1.0, Random\IntervalBoundary::ClosedOpen);
var_export($got >= 0.0 && $got < 1.0);
echo "\n";
?>
--EXPECT--
true
true
true
true
true
