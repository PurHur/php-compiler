--TEST--
stdlib wordwrap() empty $break ValueError — must not be empty (#29291, php-src string.c)
--FILE--
<?php
try {
    wordwrap('abcd', 2, '', true);
    echo "miss\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
wordwrap(): Argument #3 ($break) must not be empty
