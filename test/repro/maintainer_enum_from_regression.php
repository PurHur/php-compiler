<?php
declare(strict_types=1);

enum Color: string { case Red = 'red'; case Blue = 'blue'; }
enum Count: int { case One = 1; case Two = 2; }

// string-backed — exact match
var_dump(Color::from('red'));
var_dump(Color::tryFrom('red'));

// int-backed — int and numeric-string coercion
var_dump(Count::from(1));
var_dump(Count::tryFrom('1'));

// invalid — from throws ValueError, tryFrom null
try {
    Color::from('nope');
    echo "from_invalid_ok\n";
} catch (ValueError $e) {
    echo 'from_invalid: ', $e->getMessage(), "\n";
}
var_dump(Color::tryFrom('nope'));
