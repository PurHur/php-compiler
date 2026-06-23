<?php

declare(strict_types=1);

$f = fopen('php://memory', 'r+');
var_export(fscanf($f, '%s'));
fclose($f);
echo "\n";

$f = fopen('php://memory', 'r+');
var_export(fscanf($f, '%d'));
fclose($f);
echo "\n";
