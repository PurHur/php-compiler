<?php

declare(strict_types=1);

$name = null;
$ok = is_callable('strlen', false, $name);
var_export($ok);
echo ' ';
var_export($name);
echo "\n";

$name = null;
$ok = is_callable('NoSuchFunction_xyz', false, $name);
var_export($ok);
echo ' ';
var_export($name);
echo "\n";
