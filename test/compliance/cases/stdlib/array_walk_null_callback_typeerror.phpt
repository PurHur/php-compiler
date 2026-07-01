--TEST--
stdlib array_walk()/array_walk_recursive() — null callback TypeError (#14786, ext/standard/array.c)
--FILE--
<?php
declare(strict_types=1);

$a = [1, 2];

try {
    array_walk($a, null);
    echo "array_walk uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

try {
    array_walk_recursive($a, null);
    echo "array_walk_recursive uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

$b = [1, 2];
array_walk($b, static function (mixed &$v): void {
    $v *= 2;
});
echo implode(',', $b), "\n";
--EXPECT--
array_walk(): Argument #2 ($callback) must be a valid callback, no array or string given
array_walk_recursive(): Argument #2 ($callback) must be a valid callback, no array or string given
2,4
--EXPECT_EXIT--
0
