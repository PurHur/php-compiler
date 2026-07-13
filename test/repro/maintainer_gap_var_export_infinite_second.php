<?php

declare(strict_types=1);

$a = var_export(INF, true);
$b = var_export(INF, true);
echo strlen($a), ' ', strlen($b), "\n";
echo $a === $b ? 'same' : 'diff', "\n";
echo $a, "\n";
echo $b, "\n";

$c = var_export(-INF, true);
$d = var_export(-INF, true);
echo strlen($c), ' ', strlen($d), "\n";
echo $c, "\n";
echo $d, "\n";

$e = var_export(NAN, true);
$f = var_export(NAN, true);
echo strlen($e), ' ', strlen($f), "\n";
echo $e, "\n";
echo $f, "\n";
