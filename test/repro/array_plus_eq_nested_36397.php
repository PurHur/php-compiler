<?php
$b = ['n' => ['x' => 1]];
$a = $b;
$a['n'] += ['y' => 2];
echo isset($b['n']['y']) ? 'BHAS' : 'BOK', '|', isset($a['n']['y']) ? 'AHAS' : 'AMISS', "\n";
