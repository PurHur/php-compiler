--TEST--
stdlib array_pad() ValueError when pad amount exceeds 1048576 JIT (#26658, ext/standard/array.c)
--FILE--
<?php
try {
    array_pad([1], 1048578, 0);
    echo "ok\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
array_pad(): Argument #2 ($length) must be less than or equal to 1048576
