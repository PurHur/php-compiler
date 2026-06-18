--TEST--
language WeakMap — enum case object keys set/get/isset/unset (#8949, #9003, zend_weakrefs.c)
--FILE--
<?php
enum E: int { case A = 1; }
enum F: string { case B = 'x'; }
enum U { case C; }

$map = new WeakMap();
$map[E::A] = 42;
echo 'int set=', $map[E::A], "\n";
echo isset($map[E::A]) ? "int isset yes\n" : "int isset no\n";

$map[F::B] = 'val';
echo 'str set=', $map[F::B], "\n";

$map[U::C] = 'unit';
echo 'unit set=', $map[U::C], "\n";
echo isset($map[U::C]) ? "unit isset yes\n" : "unit isset no\n";

unset($map[E::A]);
echo isset($map[E::A]) ? "int isset after unset yes\n" : "int isset after unset no\n";

unset($map[U::C]);
echo isset($map[U::C]) ? "unit isset after unset yes\n" : "unit isset after unset no\n";

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
unit set=unit
unit isset yes
int isset after unset no
unit isset after unset no
int key err
