--TEST--
stdlib str_pad() empty $pad_string ValueError under PROFILE=8.4 — must not be empty (#29292)
--ENV--
PHP_COMPILER_PROFILE=8.4
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
str_pad(): Argument #3 ($pad_string) must not be empty
