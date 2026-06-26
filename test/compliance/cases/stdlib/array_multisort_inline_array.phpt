--TEST--
stdlib array_multisort() — inline non-empty array literals (#12017, ext/standard/array.c)
--FILE--
<?php
array_multisort([1, 2], SORT_DESC, SORT_NUMERIC);
echo "flags\n";
array_multisort([1, 2], [2, 1]);
echo "two\n";
$a = [1, 2];
array_multisort($a, SORT_DESC);
echo implode(',', $a), "\n";
try {
    sort([1, 2]);
    echo "sort ok\n";
} catch (Error $e) {
    echo 'sort: ', $e->getMessage(), "\n";
}
--EXPECT--
flags
two
2,1
sort: sort(): Argument #1 ($array) cannot be passed by reference
