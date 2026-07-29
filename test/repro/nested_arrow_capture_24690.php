<?php
// #24690 — nested arrow function loses outer variable capture

$multiplier = 3;
$fn = fn($x) => fn($y) => $x * $y * $multiplier;
echo $fn(2)(4) . "\n"; // expect 24

$fn3 = fn($a) => fn($b) => fn($c) => $a + $b + $c + $multiplier;
echo $fn3(10)(20)(30) . "\n"; // expect 63
