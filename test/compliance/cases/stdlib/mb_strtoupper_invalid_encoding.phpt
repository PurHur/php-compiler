--TEST--
stdlib mb_strtoupper()/mb_strtolower() invalid encoding — ValueError (ext/mbstring/mbstring.c, #13377)
--FILE--
<?php
try {
    mb_strtoupper('hello', 'tr_TR');
    echo "no exception\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
try {
    mb_strtolower('hello', 'tr_TR');
    echo "no exception\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
echo mb_strtoupper('hello', 'UTF-8'), "\n";
--EXPECT--
mb_strtoupper(): Argument #2 ($encoding) must be a valid encoding, "tr_TR" given
mb_strtolower(): Argument #2 ($encoding) must be a valid encoding, "tr_TR" given
HELLO
