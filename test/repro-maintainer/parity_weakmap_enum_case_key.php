<?php
enum E: int { case A = 1; }

$m = new WeakMap();
$m[E::A] = 1;
echo "set ok\n";
echo $m[E::A], "\n";
echo isset($m[E::A]) ? "isset yes\n" : "isset no\n";
