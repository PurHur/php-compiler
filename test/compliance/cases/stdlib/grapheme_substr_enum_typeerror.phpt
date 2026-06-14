--TEST--
stdlib grapheme_substr() — enum case operand TypeError (#3352, php-src-strict)
--FILE--
<?php
enum Es: string { case A = 'hi'; }
try {
    grapheme_substr(Es::A, 0);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
grapheme_substr(): Argument #1 ($string) must be of type string, Es given
