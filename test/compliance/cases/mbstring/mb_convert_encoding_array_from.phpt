--TEST--
mbstring mb_convert_encoding() array|comma-list $from_encoding try-detect (#23562, ext/mbstring/mbstring.c)
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
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}

try {
    mb_convert_encoding($bytes, 'UTF-8', ['UTF-8', 'nope']);
    echo "no_exception\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}

try {
    mb_convert_encoding($bytes, 'UTF-8', []);
    echo "no_exception\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}

$bad = "\xFF\xFE";
var_export(@mb_convert_encoding($bad, 'UTF-8', ['ASCII', 'UTF-8']));
echo "\n";

$latin1 = ["\xE9", 'foo'];
var_export(mb_convert_encoding($latin1, 'UTF-8', ['UTF-8', 'ISO-8859-1']));
echo "\n";
--EXPECT--
'é'
'é'
'é'
mb_convert_encoding(): Argument #3 ($from_encoding) contains invalid encoding "nope"
mb_convert_encoding(): Argument #3 ($from_encoding) contains invalid encoding "nope"
mb_convert_encoding(): Argument #3 ($from_encoding) must specify at least one encoding
false
array (
  0 => 'é',
  1 => 'foo',
)
