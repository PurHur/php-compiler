<?php

declare(strict_types=1);

// #35315 leftover #4572 — mb_convert_variables JIT/AOT string/array/object by-ref
$latin1Cafe = "caf\xe9";
$a = $latin1Cafe;
$r = mb_convert_variables('UTF-8', 'ISO-8859-1', $a);
echo $r, "\n";
echo bin2hex($a), "\n";

$b = 'hello';
$r2 = mb_convert_variables('UTF-8', 'UTF-8', $b);
echo $r2, "\n";
echo $b, "\n";

$c = ['x' => 'world'];
$r3 = mb_convert_variables('UTF-8', 'ISO-8859-1', $c);
echo $r3, "\n";
echo $c['x'], "\n";

$o = new stdClass();
$o->label = 'ok';
$r4 = mb_convert_variables('UTF-8', 'UTF-8', $o);
echo $r4, "\n";
echo $o->label, "\n";
