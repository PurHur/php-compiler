--TEST--
language WeakMap — enum case object keys set/get/isset/unset (#8949, zend_weakrefs.c)
--FILE--
<?php
enum E: int { case A = 1; }
enum F: string { case B = 'x'; }

$map = new WeakMap();
$map[E::A] = 42;
echo 'int set=', $map[E::A], "\n";
echo isset($map[E::A]) ? "int isset yes\n" : "int isset no\n";

$map[F::B] = 'val';
echo 'str set=', $map[F::B], "\n";

unset($map[E::A]);
echo isset($map[E::A]) ? "int isset after unset yes\n" : "int isset after unset no\n";

try {
    $map[1] = 'bad';
    echo "int key ok\n";
} catch (TypeError $e) {
    echo "int key err\n";
}
--EXPECT--
int set=42
int isset yes
str set=val
int isset after unset no
int key err
