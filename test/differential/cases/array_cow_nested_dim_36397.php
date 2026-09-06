<?php
// @differential-repeat: 10 nested FETCH_DIM_W must SEPARATE shared child HT (#36397 / php-src ZEND_FETCH_DIM_W)
$b = ['x' => ['y' => 1]];
$a = $b;
$a['x']['y'] = 9;
echo $b['x']['y'], '|', $a['x']['y'], "\n";

$d = [[1, 2]];
$c = $d;
$c[0][0] = 9;
echo $d[0][0], '|', $c[0][0], "\n";
