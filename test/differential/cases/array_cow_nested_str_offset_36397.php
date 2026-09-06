<?php
// @differential-repeat: 10 nested string-offset must not autovivify array over string (#36397)
// php-src: Zend/zend_execute.c ZEND_FETCH_DIM_W + zend_assign_to_string_offset
$b = ['s' => 'ab'];
$a = $b;
$a['s'][0] = 'X';
echo $b['s'], '|', $a['s'], '|', gettype($a['s']), "\n";

$c = ['s' => 'ab'];
$c['s'][0] = 'X';
echo $c['s'], '|', gettype($c['s']), "\n";

$d = ['ab'];
$d[0][0] = 'X';
echo $d[0], '|', gettype($d[0]), "\n";
