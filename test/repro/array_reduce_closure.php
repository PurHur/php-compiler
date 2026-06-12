<?php
echo array_reduce([1, 2, 3], fn($c, $i) => $c + $i, 0), "\n";

function add(int $c, int $i): int { return $c + $i; }
echo array_reduce([1, 2, 3], 'add', 0), "\n";
