--TEST--
stdlib grapheme_levenshtein() — enum case operand TypeError (#6998, #5914)
--FILE--
<?php
enum Es: string { case B = 'hi'; }
try {
    grapheme_levenshtein(Es::B, 'h');
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
grapheme_levenshtein(): Argument #1 ($string1) must be of type string, Es given
