--TEST--
stdlib grapheme_stripos() — enum case operand TypeError (#6153, php-src-strict)
--FILE--
<?php
enum Es: string { case B = 'hi'; }
try {
    grapheme_stripos(Es::B, 'h');
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
grapheme_stripos(): Argument #1 ($haystack) must be of type string, Es given
