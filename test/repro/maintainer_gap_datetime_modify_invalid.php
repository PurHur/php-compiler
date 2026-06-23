<?php
$dt = new DateTime('2020-01-01');
$ok = $dt->modify('+1 day');
var_export([$ok instanceof DateTime, $dt->format('Y-m-d')]);
echo "\n";
$dt2 = new DateTime('2020-01-01');
$bad = $dt2->modify('not a date');
var_export([$bad, $dt2->format('Y-m-d')]);
echo "\n";
