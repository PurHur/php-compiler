--TEST--
stdlib array_pad() oversize ValueError wording matches Zend (#29342, ext/standard/array.c)
--FILE--
<?php
try {
    array_pad([1], PHP_INT_MAX, 0);
    echo "ok\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
array_pad(): Argument #2 ($length) must not exceed the maximum allowed array size
