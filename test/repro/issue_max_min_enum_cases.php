<?php
/**
 * Repro for #5570 — max()/min() must return enum case objects, not backing scalars.
 *
 * @see ext/standard/array.c php_min_max (php-src)
 */
enum E: int { case A = 1; case B = 2; }

$m = max(E::A, E::B);
$n = min(E::A, E::B);
echo $m->name, ' ', $m->value, "\n";
echo $n->name, ' ', $n->value, "\n";
echo ($m === E::B) ? '1' : '0', "\n";
echo ($n === E::A) ? '1' : '0', "\n";
$t = max(E::A, E::B, E::A);
echo $t->name, "\n";
