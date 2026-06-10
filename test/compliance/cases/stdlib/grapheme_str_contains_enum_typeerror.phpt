--TEST--
stdlib grapheme_str_contains() — enum case operand TypeError (#7128, php-src-strict)
--FILE--
<?php
enum Es: string { case B = 'hi'; }
try {
    grapheme_str_contains(Es::B, 'h');
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
grapheme_str_contains(): Argument #1 ($haystack) must be of type string, Es given
