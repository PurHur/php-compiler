--TEST--
stdlib number_format() — enum case operands TypeError (#7443, ext/standard/number_format.c, php-src-strict)
--FILE--
<?php
enum E: string { case A = 'x'; }
try {
    number_format(1.0, E::A);
} catch (TypeError $e) {
    echo 'decimals: ', $e->getMessage(), "\n";
}
try {
    number_format(1.0, 2, E::A);
} catch (TypeError $e) {
    echo 'decimal_separator: ', $e->getMessage(), "\n";
}
--EXPECT--
decimals: number_format(): Argument #2 ($decimals) must be of type int, E given
decimal_separator: number_format(): Argument #3 ($decimal_separator) must be of type ?string, E given
