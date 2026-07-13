<?php

$h = fopen('php://memory', 'r+');
fwrite($h, '1 2 3');
rewind($h);
var_export(fscanf($h, '%d %d %d'));
echo "\n";
var_export(fscanf($h, '%s'));
echo "\n";
fclose($h);
