--TEST--
stdlib str_pad() empty $pad_string ValueError JIT — must be a non-empty string (#29755, php-src string.c)
--FILE--
<?php
try {
    str_pad('a', 5, '');
    echo "miss\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
str_pad(): Argument #3 ($pad_string) must be a non-empty string
