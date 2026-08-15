--TEST--
stdlib array_pad() ValueError when pad exceeds max allowed size (#26658/#29342, ext/standard/array.c)
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
0: array_pad(): Argument #2 ($length) must not exceed the maximum allowed array size
1: array_pad(): Argument #2 ($length) must not exceed the maximum allowed array size
2: array_pad(): Argument #2 ($length) must not exceed the maximum allowed array size
3: array_pad(): Argument #2 ($length) must not exceed the maximum allowed array size
