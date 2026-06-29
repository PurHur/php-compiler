--TEST--
stdlib path builtins reject null under declare(strict_types=1) (#13419, ext/standard/filestat.c)
--FILE--
<?php
declare(strict_types=1);
try {
    unlink(null);
    echo "fail\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
unlink(): Argument #1 ($filename) must be of type string, null given
