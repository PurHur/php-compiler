--TEST--
stdlib substr_replace() — array $string / $offset / $length (#4057, ext/standard/string.c)
--FILE--
<?php
declare(strict_types=1);

$result = substr_replace(['abcdef', '123'], '.', [2, 1], [2, 1]);
echo json_encode($result), "\n";

$result2 = substr_replace(['abc', 'def'], ['X', 'Y'], 1, 1);
echo json_encode($result2), "\n";

try {
    substr_replace('abc', 'x', [1]);
    echo "uncaught offset array on scalar\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

echo substr_replace('abc', ['x', 'y'], 0), "\n";
--EXPECT--
["ab.ef","1.3"]
["aXc","dYf"]
substr_replace(): Argument #3 ($offset) cannot be an array when working on a single string
x
