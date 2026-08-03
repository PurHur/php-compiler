<?php
// Repro #27285 — AOT SplFixedArray count/getSize must stay constructor size.
$a = new SplFixedArray(3);
$a[0] = 1;
$a[2] = 3;
echo $a[0], ',', $a[2], ',', count($a), "\n";
echo $a->getSize(), "\n";
