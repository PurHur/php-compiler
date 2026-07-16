--TEST--
Stdlib: Random\Randomizer::getBytesFromString() seeded Mt19937 (#19572, ext/random/randomizer.c, PHP 8.3+)
--ENV--
PHP_COMPILER_PROFILE=8.3
--SKIPIF--
<?php
if (!getenv('PHP_COMPILER_PROFILE') || version_compare(getenv('PHP_COMPILER_PROFILE'), '8.3', '<')) {
    die('skip requires PHP_COMPILER_PROFILE >= 8.3');
}
?>
--FILE--
<?php
declare(strict_types=1);

var_export(method_exists(Random\Randomizer::class, 'getBytesFromString'));
echo "\n";

$r = new Random\Randomizer(new Random\Engine\Mt19937(1));
echo bin2hex($r->getBytesFromString('abcdef', 8)), "\n";

$r = new Random\Randomizer(new Random\Engine\Mt19937(1));
echo bin2hex($r->getBytesFromString('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789', 16)), "\n";

$r = new Random\Randomizer(new Random\Engine\Mt19937(42));
echo bin2hex($r->getBytesFromString(str_repeat('x', 300), 4)), "\n";

try {
    (new Random\Randomizer(new Random\Engine\Mt19937(1)))->getBytesFromString('', 1);
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
try {
    (new Random\Randomizer(new Random\Engine\Mt19937(1)))->getBytesFromString('a', 0);
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
true
6665626364616561
4c3062515261686d564e346975385568
78787878
Random\Randomizer::getBytesFromString(): Argument #1 ($string) cannot be empty
Random\Randomizer::getBytesFromString(): Argument #2 ($length) must be greater than 0
