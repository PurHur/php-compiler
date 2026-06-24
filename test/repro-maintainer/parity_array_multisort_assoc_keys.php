<?php

$a = ['x' => 3, 'y' => 1];
$b = ['x' => 2, 'y' => 4];
array_multisort($a, $b);

$ok = 1 === $a['y']
    && 3 === $a['x']
    && 4 === $b['y']
    && 2 === $b['x'];

echo 'ok=' . ($ok ? 'true' : 'false') . "\n";
