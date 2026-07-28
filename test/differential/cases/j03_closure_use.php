<?php
// #24106: closure `use ()` and arrow-fn captures read as empty under AOT — `$x + $n` gave 5 not 15.
// Fixed; kept as a guard. The corpus had NO closure `use` at all before this batch.
$n = 10;
$byUse = function (int $x) use ($n): int { return $x + $n; };
$noUse = function (int $x): int { return $x + 1; };
$arrow = fn(int $x): int => $x + $n;
echo $byUse(5), ' ', $noUse(5), ' ', $arrow(5), "\n";
