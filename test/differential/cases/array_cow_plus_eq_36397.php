<?php
// @differential-repeat: 10 array += COW / AssignOp-fused union (#36397)
// php-src: Zend/zend_operators.c add_function — array+array union; SEPARATE on assign.
$b = ['x' => 1, 'y' => 2];
$a = $b;
$a += ['z' => 3];
echo isset($b['z']) ? 'BHAS' : 'BOK', '|', isset($a['z']) ? 'AHAS' : 'AMISS', '|', $a['x'], '|', $b['x'];
$c = ['n' => ['x' => 1]];
$d = $c;
$d['n'] += ['y' => 2];
echo '|', isset($c['n']['y']) ? 'CHAS' : 'COK', '|', isset($d['n']['y']) ? 'DHAS' : 'DMISS';
$e = ['p' => 1];
$e += ['q' => 2];
echo '|', isset($e['q']) ? 'EHAS' : 'EMISS', '|', $e['p'], "\n";
