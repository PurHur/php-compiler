--TEST--
stdlib array_first_key()/array_last_key() — PHP 8.4 forward profile (#16995, ext/standard/array.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);

foreach (['array_first_key', 'array_last_key'] as $fn) {
    echo $fn, ' exists=', function_exists($fn) ? 'yes' : 'no', "\n";
}

$k = array_first_key([]);
echo $k === null ? "empty_first\n" : "bad_first\n";
$k = array_last_key([]);
echo $k === null ? "empty_last\n" : "bad_last\n";

$list = [10, 20, 30];
echo array_first_key($list), "\n";
echo array_last_key($list), "\n";

$a = ['x' => 1, 'y' => 2];
echo array_first_key($a), "\n";
echo array_last_key($a), "\n";

try {
    array_first_key(null);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    array_last_key(null);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
array_first_key exists=yes
array_last_key exists=yes
empty_first
empty_last
0
2
x
y
TypeError: array_first_key(): Argument #1 ($array) must be of type array, null given
TypeError: array_last_key(): Argument #1 ($array) must be of type array, null given
