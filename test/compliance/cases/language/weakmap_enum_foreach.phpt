--TEST--
language WeakMap — foreach yields enum case object keys (#8949, #8264, zend_weakrefs.c)
--FILE--
<?php
enum E: int { case A = 1; }
enum U { case C; }

$map = new WeakMap();
$map[E::A] = 42;
$map[U::C] = 'unit';

foreach ($map as $k => $v) {
    if ($v === 42) {
        echo get_debug_type($k), ' ', $v, "\n";
    } else {
        echo get_debug_type($k), ' ', $v, "\n";
    }
}
--EXPECT--
E 42
U unit
