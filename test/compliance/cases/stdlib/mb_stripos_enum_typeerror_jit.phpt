--TEST--
stdlib mb_stripos() JIT — backed enum case TypeError (#7015)
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
