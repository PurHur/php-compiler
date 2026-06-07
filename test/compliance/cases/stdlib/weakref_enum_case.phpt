--TEST--
stdlib WeakReference::create() on enum case (#5681, zend_weakrefs.c)
--FILE--
<?php
enum E { case A; }
$w = WeakReference::create(E::A);
var_export($w->get() === E::A);
echo "\n";
try {
    WeakReference::create(1);
    echo "int_ok\n";
} catch (TypeError $e) {
    echo "int_typeerror\n";
}
enum F: string { case B = 'x'; }
$map = new WeakMap();
$map[F::B] = 'v';
echo $map[F::B], "\n";
--EXPECT--
true
int_typeerror
v
