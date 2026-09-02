<?php
// Runtime int bounds — must compile under AOT (#36243).
$n = (int) ($argv[1] ?? 10);
$a = range(0, $n - 1);
echo count($a), "\n";
