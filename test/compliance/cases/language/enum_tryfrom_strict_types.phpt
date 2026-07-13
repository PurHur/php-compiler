--TEST--
Language: BackedEnum::from()/tryFrom() reject non-exact backing under strict_types (#18476, zend_enum.c)
--FILE--
<?php
declare(strict_types=1);

enum Count: int { case One = 1; }
enum Color: string { case Red = 'red'; }

try {
    Count::tryFrom('1');
    echo "tryFrom int string uncaught\n";
} catch (TypeError $e) {
    echo 'tryFrom int string: ', $e->getMessage(), "\n";
}

try {
    Color::tryFrom(1);
    echo "tryFrom string int uncaught\n";
} catch (TypeError $e) {
    echo 'tryFrom string int: ', $e->getMessage(), "\n";
}

var_dump(Count::tryFrom(1));
--EXPECT--
tryFrom int string: Count::tryFrom(): Argument #1 ($value) must be of type int, string given
tryFrom string int: Color::tryFrom(): Argument #1 ($value) must be of type string, int given
enum(Count::One)
