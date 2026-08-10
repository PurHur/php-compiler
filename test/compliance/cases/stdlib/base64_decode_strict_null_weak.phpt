--TEST--
stdlib base64_decode(null $strict) without strict_types — Deprecated + coerce (#29867, ext/standard/base64.c)
--FILE--
<?php
$prev = set_error_handler(static function (int $errno, string $errstr): bool {
    if (E_DEPRECATED === $errno) {
        echo 'DEP:', $errstr, "\n";

        return true;
    }

    return false;
});
var_export(base64_decode('YQ==', null));
echo "\n";
restore_error_handler();
--EXPECT--
DEP:base64_decode(): Passing null to parameter #2 ($strict) of type bool is deprecated
'a'
