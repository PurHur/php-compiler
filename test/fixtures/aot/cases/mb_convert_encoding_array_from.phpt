--TEST--
AOT: mb_convert_encoding() array|comma-list $from_encoding detect-then-convert (#35296 leftover of #23562)
--FILE--
<?php
$bytes = "\xE9";
var_export(mb_convert_encoding($bytes, 'UTF-8', ['UTF-8', 'ISO-8859-1']));
echo "\n";
var_export(mb_convert_encoding($bytes, 'UTF-8', ['ISO-8859-1', 'UTF-8']));
echo "\n";
var_export(mb_convert_encoding($bytes, 'UTF-8', 'UTF-8,ISO-8859-1'));
echo "\n";
try {
    mb_convert_encoding($bytes, 'UTF-8', ['nope']);
    echo "no_exception\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    mb_convert_encoding($bytes, 'UTF-8', []);
    echo "no_exception\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
$bad = "\xFF\xFE";
var_export(@mb_convert_encoding($bad, 'UTF-8', ['ASCII', 'UTF-8']));
echo "\n";
--EXPECT--
'é'
'é'
'é'
ValueError: mb_convert_encoding(): Argument #3 ($from_encoding) contains invalid encoding "nope"
ValueError: mb_convert_encoding(): Argument #3 ($from_encoding) must specify at least one encoding
false
