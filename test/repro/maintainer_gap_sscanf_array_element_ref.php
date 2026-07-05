<?php

declare(strict_types=1);

$a = [];
$n = sscanf('42', '%d', $a[0]);
var_export([$n, $a]);
echo "\n";

$b = [];
$c = 0;
$n2 = sscanf('10 20', '%d %d', $b[0], $c);
var_export([$n2, $b, $c]);
echo "\n";
