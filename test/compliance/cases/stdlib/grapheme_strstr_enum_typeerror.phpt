--TEST--
stdlib grapheme_strstr() — enum case operand TypeError (#7221, #5914)
--FILE--
<?php
enum Es: string { case A = 'a'; }
try {
    grapheme_strstr('ab', Es::A);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
grapheme_strstr(): Argument #2 ($needle) must be of type string, Es given
