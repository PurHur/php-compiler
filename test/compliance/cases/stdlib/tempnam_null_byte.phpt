--TEST--
stdlib tempnam() null byte in directory must ValueError (php-src Z_PARAM_PATH, #4401)
--FILE--
<?php
try {
    tempnam("a\0b", 'pfx');
    echo "no-exception\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
tempnam(): Argument #1 ($directory) must not contain any null bytes
