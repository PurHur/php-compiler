<?php
$t = 1700000000;
var_export(gmgetdate($t));
echo "\n";
var_export(gmmktime(22, 13, 20, 11, 14, 2023));
echo "\n";
$d = gmgetdate(946684800);
echo $d['year'], '-', $d['mon'], '-', $d['mday'], "\n";
echo $d['hours'], ':', $d['minutes'], ':', $d['seconds'], "\n";
