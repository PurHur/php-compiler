--TEST--
stdlib strlen() JIT — null deprecated, returns 0 (#5000)
--FILE--
<?php
try {
    echo strlen(null), "\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
strlen(): Argument #1 ($string) must be of type string, null given
