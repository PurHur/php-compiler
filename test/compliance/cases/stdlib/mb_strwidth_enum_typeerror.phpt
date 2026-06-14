--TEST--
stdlib mb_strwidth() — enum case operand TypeError (#3495, php-src-strict)
--FILE--
<?php
enum Es: string { case B = 'b'; }
try {
    mb_strwidth(Es::B);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
mb_strwidth(): Argument #1 ($string) must be of type string, Es given
