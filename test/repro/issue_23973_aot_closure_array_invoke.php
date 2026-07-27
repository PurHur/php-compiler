<?php
// #23973 — array-stored closures force RuntimeIndirectClosureCall (multi-candidate).
$a = [function ($x) { return $x + 1; }, fn ($x) => $x * 2];
echo $a[0](41), "\n";
echo $a[1](21), "\n";
