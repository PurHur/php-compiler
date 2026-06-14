--TEST--
stdlib WeakMap — int-backed enum case object key (#5629, zend_weakrefs.c)
--FILE--
<?php
enum E: int { case A = 1; }

$m = new WeakMap();
$m[E::A] = 1;
echo "set ok\n";
echo $m[E::A], "\n";
echo isset($m[E::A]) ? "isset yes\n" : "isset no\n";
--EXPECT--
set ok
1
isset yes
