<?php
$a = ['x' => 1];
$a += ['z' => 3];
echo isset($a['z']) ? 'AHAS' : 'AMISS', '|', $a['x'], "\n";
