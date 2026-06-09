<?php
declare(strict_types=1);

$fp = fopen('php://memory', 'r+');
fclose($fp);
var_export($fp);
echo "\n";
var_export([$fp]);
echo "\n";
