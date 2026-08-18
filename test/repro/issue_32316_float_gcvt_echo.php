<?php
// Repro #32316 — AOT echo / var_dump float display must match zend_gcvt
declare(strict_types=1);

$large = 1.0E+100;
echo $large, "\n";
$small = 1.0E-5;
echo $small, "\n";
$neg = -1.0E+20;
echo $neg, "\n";
$frac = 1.5E+20;
echo $frac, "\n";
echo 3.5, "\n";
var_dump(INF);
echo (string) $large, "\n";
