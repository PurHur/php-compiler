--TEST--
stdlib substr_count() empty needle ValueError — must not be empty (#29276, php-src string.c)
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
substr_count(): Argument #2 ($needle) must not be empty
