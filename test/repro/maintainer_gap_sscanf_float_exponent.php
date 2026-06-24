<?php

declare(strict_types=1);

var_export(sscanf('1.5e2', '%f'));
echo "\n";
var_export(sscanf('1.5E-1', '%f'));
echo "\n";
var_export(sscanf('  2.5e+3xyz', '%f'));
echo "\n";
