--TEST--
stdlib mb_stripos() — backed enum case TypeError (#7015, php-src-strict)
--FILE--
<?php
enum Es: string { case B = 'hi'; }
try {
    mb_stripos(Es::B, 'h');
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
mb_stripos(): Argument #1 ($haystack) must be of type string, Es given
