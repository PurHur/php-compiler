<?php
$a = array();
$a[] = 'a10';
$a[] = 'a2';
sort($a, SORT_NATURAL);
echo implode(',', $a), "\n";

$b = array('a' => 1);
$name = 'keep';
extract($b, EXTR_SKIP);
echo isset($name) ? $name : 'skip', "\n";
