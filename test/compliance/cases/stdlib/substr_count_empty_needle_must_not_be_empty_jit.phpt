--TEST--
stdlib substr_count() empty needle ValueError JIT — cannot be empty (#30522, was #29276, php-src string.c)
--FILE--
<?php
try {
    substr_count('abc', '');
    echo "miss\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
substr_count(): Argument #2 ($needle) cannot be empty
