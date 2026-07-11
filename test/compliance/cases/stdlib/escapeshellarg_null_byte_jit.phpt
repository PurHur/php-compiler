--TEST--
stdlib escapeshellarg() JIT rejects embedded NUL (#12497)
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
