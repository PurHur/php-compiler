--TEST--
stdlib random_bytes() JIT — numeric-string coercion + array TypeError (#4626)
--FILE--
<?php
echo strlen(random_bytes(8)), "\n";
echo strlen(random_bytes('16')), "\n";
try {
    random_bytes([]);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    random_bytes(0);
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
8
16
random_bytes(): Argument #1 ($length) must be of type int, array given
random_bytes(): Argument #1 ($length) must be greater than 0
