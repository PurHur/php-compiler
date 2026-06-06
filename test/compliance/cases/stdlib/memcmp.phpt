--TEST--
stdlib memcmp() (#7118, ext/standard/string.c)
--FILE--
<?php
declare(strict_types=1);
var_export(memcmp('abc', 'abd', 3));
echo "\n";
var_export(memcmp('abc', 'ab', 3));
echo "\n";
var_export(memcmp('ab', 'abc', 3));
echo "\n";
try {
    memcmp([], 'a', 1);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
-1
1
-1
TypeError: memcmp(): Argument #1 ($string1) must be of type string, array given
