--TEST--
stdlib grapheme_strlen() — enum case operand TypeError (#5914, php-src-strict)
--FILE--
<?php
enum Es: string { case A = 'á'; }
try {
    grapheme_strlen(Es::A);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
grapheme_strlen(): Argument #1 ($string) must be of type string, Es given
