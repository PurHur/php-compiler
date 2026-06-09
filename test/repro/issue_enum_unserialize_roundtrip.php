<?php
declare(strict_types=1);
enum E: int { case A = 1; }
$s = serialize(E::A);
echo $s, "\n";
$u = unserialize($s);
var_export($u);
echo "\n", ($u === E::A) ? "same\n" : "diff\n";
