--TEST--
stdlib settype() backed enum to int JIT — Zend object→int coercion (#8787, ext/standard/type.c)
--JIT--
--FILE--
<?php
declare(strict_types=1);

enum E: int
{
    case A = 42;
}

$x = E::A;
@settype($x, 'int');
echo $x, ' ', gettype($x), "\n";

$x = E::A;
@settype($x, 'float');
echo $x, ' ', gettype($x), "\n";

$x = E::A;
settype($x, 'bool');
echo (int) $x, ' ', gettype($x), "\n";

$x = E::A;
settype($x, 'object');
echo gettype($x), ' ', ($x instanceof E ? 'E' : '?'), "\n";
?>
--EXPECT--
1 integer
1 double
1 boolean
object E
