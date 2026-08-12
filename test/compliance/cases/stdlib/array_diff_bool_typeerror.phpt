--TEST--
array_diff/intersect/merge variadic bool TypeError cites bool not true|false (#30480)
--FILE--
<?php
foreach ([
    static fn () => array_diff([1, 2, 3], [2, 4], true),
    static fn () => array_intersect([1, 2], [1], false),
    static fn () => array_merge([1], true),
] as $i => $call) {
    try {
        $call();
    } catch (Throwable $e) {
        echo $i, ':', $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
0:array_diff(): Argument #3 must be of type array, bool given
1:array_intersect(): Argument #3 must be of type array, bool given
2:array_merge(): Argument #2 must be of type array, bool given
