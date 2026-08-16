--TEST--
http_build_query(null $numeric_prefix) soft-null DEP + coerce "" (#29721, ext/standard/http.c)
--FILE--
<?php
error_reporting(E_ALL);
set_error_handler(static function (int $errno, string $errstr): bool {
    $label = match ($errno) {
        E_DEPRECATED => 'DEPRECATED',
        E_WARNING => 'WARNING',
        default => (string) $errno,
    };
    echo $label, ': ', $errstr, "\n";

    return true;
});
var_export(http_build_query(['a' => 1], null));
echo "\n";
var_export(http_build_query(['a' => 1]));
echo "\n";
var_export(http_build_query(['a' => 1], ''));
echo "\n";
--EXPECT--
DEPRECATED: http_build_query(): Passing null to parameter #2 ($numeric_prefix) of type string is deprecated
'a=1'
'a=1'
'a=1'
