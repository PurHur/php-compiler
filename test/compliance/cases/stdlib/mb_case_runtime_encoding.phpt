--TEST--
stdlib mb_strtolower/toupper runtime encoding + invalid ValueError (#34858, ext/mbstring/mbstring.c)
--FILE--
<?php
$e = 'UTF-8';
echo mb_strtolower('Ä', $e), "\n";
echo mb_strtoupper('ä', $e), "\n";
try {
    $bad = 'NO_SUCH_ENCODING';
    mb_strtolower('hello', $bad);
    echo "no error\n";
} catch (ValueError $err) {
    echo $err->getMessage(), "\n";
}
--EXPECT--
ä
Ä
mb_strtolower(): Argument #2 ($encoding) must be a valid encoding, "NO_SUCH_ENCODING" given
