--TEST--
stdlib array_diff_assoc()/array_intersect_assoc() — null first arg TypeError matches Zend (#19845, ext/standard/array.c)
--FILE--
<?php
declare(strict_types=1);

foreach (['array_diff_assoc', 'array_intersect_assoc'] as $fn) {
    $a = null;
    try {
        $fn($a, [1]);
        echo $fn, ": uncaught\n";
    } catch (TypeError $e) {
        echo $fn, ": ", $e->getMessage(), "\n";
    }
}

$b = null;
try {
    array_diff_assoc([1], $b);
    echo "arg2 uncaught\n";
} catch (TypeError $e) {
    echo "arg2: ", $e->getMessage(), "\n";
}

$c = 1;
try {
    array_diff_assoc($c, [1]);
    echo "int uncaught\n";
} catch (TypeError $e) {
    echo "int: ", $e->getMessage(), "\n";
}
--EXPECT--
array_diff_assoc: array_diff_assoc(): Argument #1 ($array) must be of type array, null given
array_intersect_assoc: array_intersect_assoc(): Argument #1 ($array) must be of type array, null given
arg2: array_diff_assoc(): Argument #2 must be of type array, null given
int: array_diff_assoc(): Argument #1 ($array) must be of type array, int given
--EXPECT_EXIT--
0
