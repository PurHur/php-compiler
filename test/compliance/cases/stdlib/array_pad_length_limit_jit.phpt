--TEST--
stdlib array_pad() ValueError when pad exceeds max allowed size JIT (#26658/#29342, ext/standard/array.c)
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
array_pad(): Argument #2 ($length) must not exceed the maximum allowed array size
