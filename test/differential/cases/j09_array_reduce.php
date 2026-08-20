<?php
$a = [1, 2, 3, 4];
echo array_reduce($a, fn($c, $x) => $c + $x, 0), "\n";
