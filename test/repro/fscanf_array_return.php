<?php

$h = fopen('php://memory', 'r+');
fwrite($h, '42');
rewind($h);
var_export(fscanf($h, '%d'));
fclose($h);
echo "\n";

$h = fopen('php://memory', 'r+');
fwrite($h, 'x');
rewind($h);
var_export(fscanf($h, '%d'));
fclose($h);
echo "\n";
