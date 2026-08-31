<?php
// #31991: nested dim assign-op autovivifies missing intermediate keys (Zend/zend_execute.c ZEND_FETCH_DIM_W).
error_reporting(E_ALL);
$c = [];
$c['x']['y'] += 1;
echo 'nest=', $c['x']['y'], "\n";
$d = [];
$d[0][1] += 1;
echo 'idx=', $d[0][1], "\n";
