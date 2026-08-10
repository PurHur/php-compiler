--TEST--
AOT: substr_compare(null $case_insensitive) under strict_types TypeError (#29756, ext/standard/string.c Z_PARAM_BOOL)
--FILE--
<?php
declare(strict_types=1);

try {
    echo substr_compare('abc', 'ab', 0, 2, null), "\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
substr_compare(): Argument #5 ($case_insensitive) must be of type bool, null given
