<?php

declare(strict_types=1);

$fp = fopen('php://memory', 'r+');
fwrite($fp, '1.5e2');
rewind($fp);
var_export(fscanf($fp, '%f'));
echo "\n";
fclose($fp);
