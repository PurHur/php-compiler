--TEST--
Backed enum case objects pass enum-typed and union DNF parameters (issue #5779, zend_execute.c)
--FILE--
<?php
enum E: int { case A = 1; }

function g(E $e): void {
    echo $e->name, "\n";
}

function h(E|int $x): void {
    echo is_int($x) ? 'int' : $x->name, "\n";
}

function f(?E $e): string {
    return $e?->name ?? 'null';
}

g(E::A);
h(E::A);
echo f(E::A), "\n";
echo f(null), "\n";
?>
--EXPECT--
A
A
A
null
