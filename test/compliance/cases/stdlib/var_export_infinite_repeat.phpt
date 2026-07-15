--TEST--
stdlib var_export() repeated INF/-INF/NAN tokens match php-src (#18426, ext/standard/var.c)
--FILE--
<?php
$a = var_export(INF, true);
$b = var_export(INF, true);
echo strlen($a), ' ', strlen($b), "\n";
echo $a, "\n";
echo $b, "\n";
$c = var_export(-INF, true);
$d = var_export(-INF, true);
echo $c, "\n";
echo $d, "\n";
$e = var_export(NAN, true);
$f = var_export(NAN, true);
echo $e, "\n";
echo $f, "\n";
--EXPECT--
3 3
INF
INF
-INF
-INF
NAN
NAN
