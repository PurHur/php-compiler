--TEST--
mbstring mb_convert_encoding() invalid encoding ValueError message (#17457, ext/mbstring/mbstring.c)
--FILE--
<?php
try {
    mb_convert_encoding('x', 'UTF-8', 'INVALID');
    echo "uncaught\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
try {
    mb_convert_encoding('x', 'INVALID', 'UTF-8');
    echo "uncaught\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
mb_convert_encoding(): Argument #3 ($from_encoding) contains invalid encoding "INVALID"
mb_convert_encoding(): Argument #2 ($to_encoding) must be a valid encoding, "INVALID" given
