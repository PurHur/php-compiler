<?php
declare(strict_types=1);

enum E: int { case A = 1; }

$o = (object) E::A;
var_export($o === E::A);
echo "\n";
var_export($o instanceof E);
echo "\n";
var_export($o);
echo "\n";
