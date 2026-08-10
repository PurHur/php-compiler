--TEST--
stdlib substr_compare(null $case_insensitive) under strict_types TypeError (#29756, ext/standard/string.c Z_PARAM_BOOL)
--FILE--
<?php
declare(strict_types=1);

try {
    substr_compare('abc', 'ab', 0, 2, null);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    substr_compare('abc', 'ab', 0, 2, 1);
    echo "uncaught-int\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
substr_compare(): Argument #5 ($case_insensitive) must be of type bool, null given
substr_compare(): Argument #5 ($case_insensitive) must be of type bool, int given
