<?php
// #35079 — (object)$assignedArray must promote keys (not wrap as stdClass.scalar).
$a = ['a' => 1, 'b' => 2];
$o = (object) $a;
echo $o->a, ',', $o->b, "\n";
var_dump($o);
