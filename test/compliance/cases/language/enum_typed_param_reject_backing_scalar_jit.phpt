--TEST--
Enum-typed parameters reject backing scalars (JIT, #6145, zend_type_hold.c)
--FILE--
<?php
enum Color: string { case Red = 'red'; }
enum U: int { case A = 1; }

function f(Color $c): string {
    return $c->name;
}

function g(U $u): string {
    return $u->name;
}

echo 'ok=' . f(Color::Red) . "\n";

try {
    f('red');
    echo "bad_color=fail\n";
} catch (TypeError $e) {
    echo 'color:TypeError:', $e->getMessage(), "\n";
}

echo 'unit=' . g(U::A) . "\n";

try {
    g(1);
    echo "unit=fail\n";
} catch (TypeError $e) {
    echo 'unit:TypeError:', $e->getMessage(), "\n";
}
?>
--EXPECT--
ok=Red
color:TypeError:Argument must be of type Color, string given
unit=A
unit:TypeError:Argument must be of type U, int given
