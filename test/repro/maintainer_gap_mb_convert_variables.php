<?php

declare(strict_types=1);

// Compliance shape from stdlib/mb_convert_variables.phpt (#4572 / #35315 array+object leftover)
$a = 'hello';
$b = ['x' => 'world'];
$r = mb_convert_variables('UTF-8', 'ISO-8859-1', $a, $b);
echo $r, "\n";
echo $a, "\n";
echo $b['x'], "\n";
$o = new stdClass();
$o->label = 'ok';
$r2 = mb_convert_variables('UTF-8', 'UTF-8', $o);
echo $r2, "\n";
echo $o->label, "\n";
