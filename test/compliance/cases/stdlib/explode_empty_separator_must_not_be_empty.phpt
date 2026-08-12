--TEST--
stdlib explode() empty separator ValueError — cannot be empty (#30505, was #29275, php-src string.c)
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
explode(): Argument #1 ($separator) cannot be empty
