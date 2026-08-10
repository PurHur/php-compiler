--TEST--
stdlib substr_replace(null $replace) without strict_types — Deprecated + coerce (#29874, ext/standard/string.c)
--FILE--
<?php
$prev = set_error_handler(static function (int $errno, string $errstr): bool {
    if (E_DEPRECATED === $errno) {
        echo 'DEP:', $errstr, "\n";

        return true;
    }

    return false;
});
var_export(substr_replace('abc', null, 1));
echo "\n";
restore_error_handler();
--EXPECT--
DEP:substr_replace(): Passing null to parameter #2 ($replace) of type array|string is deprecated
'a'
