<?php

declare(strict_types=1);

$t = 1700000000;
echo function_exists('localtime') ? "exists\n" : "missing\n";
var_export(localtime($t));
echo "\n";
var_export(localtime($t, true));
echo "\n";
