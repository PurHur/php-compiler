--TEST--
stdlib mb_strlen() null encoding + invalid encoding ValueError (#4405, ext/mbstring/mbstring.c)
--FILE--
<?php
echo mb_strlen('é', 'UTF-8'), "\n";
var_dump(mb_strlen('hello', null));
try {
    mb_strlen('hello', 'NO_SUCH_ENCODING');
    echo "no error\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
echo mb_strlen('abc', 'ISO-8859-1'), "\n";
--EXPECT--
1
int(5)
mb_strlen(): Argument #2 ($encoding) must be a valid encoding, "NO_SUCH_ENCODING" given
3
