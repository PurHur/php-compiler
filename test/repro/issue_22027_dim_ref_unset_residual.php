<?php
/** Repro #22027 — $b=&$a[$k]; unset($a); residual must survive (Zend IS_REFERENCE). */
$a = ["x" => 1];
$b =& $a["x"];
unset($a);
var_export($b);
echo "\n";

$c = ["x" => ["y" => 2]];
$d =& $c["x"]["y"];
unset($c);
var_export($d);
echo "\n";

$e = [10, 20];
$f =& $e[1];
unset($e);
var_export($f);
echo "\n";
