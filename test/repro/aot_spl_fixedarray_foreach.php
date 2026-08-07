<?php
// Repro #28640 — AOT SplFixedArray foreach must walk `__spl_ht` packed slots.
$a = new SplFixedArray(3);
$a[0] = 10;
$a[1] = 20;
$a[2] = 30;
$sum = 0;
foreach ($a as $v) {
    $sum += $v;
}
echo $sum, "\n";
