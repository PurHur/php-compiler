<?php
$b = ['x' => 1, 'y' => 2];
$a = $b;
$a += ['z' => 3];
echo isset($b['z']) ? 'BHAS' : 'BOK', '|', isset($a['z']) ? 'AHAS' : 'AMISS', '|', $a['x'], '|', $b['x'], "\n";
