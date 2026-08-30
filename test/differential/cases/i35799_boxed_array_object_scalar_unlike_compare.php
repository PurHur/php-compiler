<?php
// #35799: assigned TYPE_VALUE array/object vs native int uses zend_compare (not 0<=>n).
error_reporting(E_ALL & ~E_NOTICE);
$a = [];
echo $a <=> 1, "\n";
echo ($a > 1) ? "agt\n" : "nagt\n";
echo 1 <=> $a, "\n";
$a2 = [1];
echo $a2 <=> 0, "\n";
$o = new stdClass();
echo $o <=> 1, "\n";
echo ($o > 1) ? "ogt\n" : "nogt\n";
echo 1 <=> $o, "\n";
