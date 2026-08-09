--TEST--
mbstring mb_str_pad() empty $pad_string ValueError — must not be empty (#29422, php-src mbstring.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
try {
    mb_str_pad('a', 5, '');
    echo "miss\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
mb_str_pad(): Argument #3 ($pad_string) must not be empty
