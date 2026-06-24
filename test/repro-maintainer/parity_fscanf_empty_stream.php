<?php

declare(strict_types=1);

$f = fopen('php://memory', 'r+');
$r = fscanf($f, '%d', $v);
fclose($f);
var_export($r);
echo "\n";
var_export($v);
