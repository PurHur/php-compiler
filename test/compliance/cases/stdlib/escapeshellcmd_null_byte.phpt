--TEST--
stdlib escapeshellcmd() rejects embedded NUL (php-src ext/standard/exec.c, #12497)
--FILE--
<?php
try {
    escapeshellcmd("a\0b");
    echo "accepted\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
escapeshellcmd(): Argument #1 ($command) must not contain any null bytes
