<?php
declare(strict_types=1);

var_export(readfile('php://output'));
echo "\n";
var_export(readfile('php://stdin'));
echo "\n";
var_export(readfile('php://memory'));
echo "\n";

$h = fopen('php://output', 'wb');
var_export(fpassthru($h));
echo "\n";
