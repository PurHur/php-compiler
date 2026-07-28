<?php
$n = 10;
$f = function (int $x) use ($n): int { return $x + $n; };
echo $f(5), "\n";
