<?php

declare(strict_types=1);

// #28673 — AOT associative array unpack [...$a,...$b] must match Zend/VM/JIT.
$a = ['x' => 1, 'y' => 2];
$b = ['y' => 9, 'z' => 3];
$c = [...$a, ...$b];
echo json_encode($c), "\n";
