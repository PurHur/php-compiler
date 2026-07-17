--TEST--
stdlib array_diff()/array_intersect()/array_replace_recursive() JIT — null first arg TypeError includes ($array) (#19846, ext/standard/array.c)
--FILE--
<?php
declare(strict_types=1);

foreach (['array_diff', 'array_intersect'] as $fn) {
    $a = null;
    try {
        $fn($a, [1]);
        echo $fn, ": uncaught\n";
    } catch (TypeError $e) {
        echo $fn, ": ", $e->getMessage(), "\n";
    }
}

$r = null;
try {
    array_replace_recursive($r);
    echo "array_replace_recursive: uncaught\n";
} catch (TypeError $e) {
    echo "array_replace_recursive: ", $e->getMessage(), "\n";
}

$b = null;
try {
    array_diff([1], $b);
    echo "arg2 uncaught\n";
} catch (TypeError $e) {
    echo "arg2: ", $e->getMessage(), "\n";
}

$c = 1;
try {
    array_diff($c, [1]);
    echo "int uncaught\n";
} catch (TypeError $e) {
    echo "int: ", $e->getMessage(), "\n";
}
--EXPECT--
array_diff: array_diff(): Argument #1 ($array) must be of type array, null given
array_intersect: array_intersect(): Argument #1 ($array) must be of type array, null given
array_replace_recursive: array_replace_recursive(): Argument #1 ($array) must be of type array, null given
arg2: array_diff(): Argument #2 must be of type array, null given
int: array_diff(): Argument #1 ($array) must be of type array, int given
--EXPECT_EXIT--
0
