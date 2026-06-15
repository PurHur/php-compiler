--TEST--
stdlib mb_encode_mimeheader() — backed enum case TypeError (#6038, php-src-strict)
--FILE--
<?php
enum Es: string { case B = 'hi'; }
try {
    mb_encode_mimeheader(Es::B);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
mb_encode_mimeheader(): Argument #1 ($str) must be of type string, Es given
