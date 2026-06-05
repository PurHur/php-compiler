<?php

enum Color: string { case Red = 'red'; case Green = 'green'; }
enum U: int { case A = 1; case B = 2; }

function f(Color $c): string {
    return $c->name;
}

function g(U $u): string {
    return $u->name;
}

echo 'ok=' . f(Color::Red) . "\n";

try {
    echo 'bad_color=' . f('red') . "\n";
} catch (TypeError $e) {
    echo 'color:' . $e->getMessage() . "\n";
}

echo 'unit=' . g(U::A) . "\n";

try {
    echo 'unit:' . g(1) . "\n";
} catch (TypeError $e) {
    echo 'unit:' . $e->getMessage() . "\n";
}
