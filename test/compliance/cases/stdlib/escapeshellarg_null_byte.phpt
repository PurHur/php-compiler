--TEST--
stdlib escapeshellarg() rejects embedded NUL (php-src ext/standard/exec.c, #12497)
--FILE--
<?php
try {
    escapeshellarg("a\0b");
    echo "accepted\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
escapeshellarg(): Argument #1 ($arg) must not contain any null bytes
