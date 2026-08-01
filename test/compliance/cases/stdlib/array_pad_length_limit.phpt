--TEST--
stdlib array_pad() ValueError when pad amount exceeds 1048576 (#26658, ext/standard/array.c)
--FILE--
<?php
foreach (
    [
        [[1], 1048578],
        [[1], -1048578],
        [[], 1048577],
        [range(1, 100), 1048677],
    ] as $i => [$array, $length]
) {
    try {
        array_pad($array, $length, 0);
        echo "$i: ok\n";
    } catch (ValueError $e) {
        echo "$i: ", $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
0: array_pad(): Argument #2 ($length) must be less than or equal to 1048576
1: array_pad(): Argument #2 ($length) must be less than or equal to 1048576
2: array_pad(): Argument #2 ($length) must be less than or equal to 1048576
3: array_pad(): Argument #2 ($length) must be less than or equal to 1048576
