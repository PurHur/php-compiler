--TEST--
stdlib substr_replace() null $offset DEP type array|int JIT (#29396, ext/standard/string.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL);
set_error_handler(static function (int $errno, string $errstr): bool {
    if (E_DEPRECATED === $errno) {
        echo 'DEP:', $errstr, "\n";

        return true;
    }

    return false;
});
echo substr_replace('abcdef', 'X', null, 1), "\n";
?>
--EXPECT--
DEP:substr_replace(): Passing null to parameter #3 ($offset) of type array|int is deprecated
Xbcdef
