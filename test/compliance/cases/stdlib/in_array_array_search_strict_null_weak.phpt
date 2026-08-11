--TEST--
stdlib in_array/array_search(null $strict) without strict_types — Deprecated + coerce (#29866, ext/standard/array.c)
--FILE--
<?php
$prev = set_error_handler(static function (int $errno, string $errstr): bool {
    if (E_DEPRECATED === $errno) {
        echo 'DEP:', $errstr, "\n";

        return true;
    }

    return false;
});
var_export(in_array(1, [1], null));
echo "\n";
var_export(array_search(1, [1], null));
echo "\n";
restore_error_handler();
--EXPECT--
DEP:in_array(): Passing null to parameter #3 ($strict) of type bool is deprecated
true
DEP:array_search(): Passing null to parameter #3 ($strict) of type bool is deprecated
0
