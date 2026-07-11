--TEST--
stdlib mb_internal_encoding() invalid name — ValueError (ext/mbstring/mbstring.c, #13376)
--FILE--
<?php
try {
    mb_internal_encoding('not-a-real-encoding');
    echo "no exception\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
echo mb_internal_encoding(), "\n";
var_export(mb_internal_encoding('SJIS'));
echo "\n";
echo mb_internal_encoding(), "\n";
mb_internal_encoding('UTF-8');
--EXPECT--
mb_internal_encoding(): Argument #1 ($encoding) must be a valid encoding, "not-a-real-encoding" given
UTF-8
true
SJIS
