--TEST--
stdlib convert_uuencode()/convert_uudecode() — backed enum case TypeError (#6259, ext/standard/uuencode.c, php-src-strict)
--FILE--
<?php
enum Es: string { case A = 'x'; }
try {
    convert_uuencode(Es::A);
    echo "encode uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    convert_uudecode(Es::A);
    echo "decode uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
$raw = "hello";
echo convert_uudecode(convert_uuencode($raw)), "\n";
--EXPECT--
convert_uuencode(): Argument #1 ($string) must be of type string, Es given
convert_uudecode(): Argument #1 ($string) must be of type string, Es given
hello
