--TEST--
stdlib grapheme_str_split() — enum case operand TypeError (#5958, #5914)
--FILE--
<?php
enum Es: string { case B = 'hi'; }
try {
    grapheme_str_split(Es::B);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
grapheme_str_split(): Argument #1 ($string) must be of type string, Es given
