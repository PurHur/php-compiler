<?php

declare(strict_types=1);

$a = [NAN];
$b = [NAN];
var_export(array_intersect($a, $b));
echo "\n";
var_export(array_intersect([NAN], [NAN]));
echo "\n";
