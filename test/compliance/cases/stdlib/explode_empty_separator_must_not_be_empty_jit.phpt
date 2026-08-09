--TEST--
stdlib explode() empty separator ValueError JIT — must not be empty (#29275, php-src string.c)
--FILE--
<?php
try {
    explode('', 'a,b');
    echo "miss\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
explode(): Argument #1 ($separator) must not be empty
