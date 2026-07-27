--TEST--
Stdlib: serialize()/unserialize() nested enum in array/object round-trip (#23692, ext/standard/var.c)
--FILE--
<?php
enum E { case A; }
enum BackedE: string { case X = 'x'; }

echo serialize([E::A]), "\n";
echo unserialize(serialize([E::A]))[0]->name, "\n";

echo serialize([BackedE::X]), "\n";
echo unserialize(serialize([BackedE::X]))[0]->name, "\n";

$o = new stdClass;
$o->e = E::A;
echo serialize($o), "\n";
echo unserialize(serialize($o))->e->name, "\n";

$o2 = new stdClass;
$o2->b = BackedE::X;
echo serialize($o2), "\n";
echo unserialize(serialize($o2))->b->value, "\n";
--EXPECT--
a:1:{i:0;E:3:"E:A";}
A
a:1:{i:0;E:9:"BackedE:X";}
X
O:8:"stdClass":1:{s:1:"e";E:3:"E:A";}
A
O:8:"stdClass":1:{s:1:"b";E:9:"BackedE:X";}
x
