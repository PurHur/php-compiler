<?php

declare(strict_types=1);

$fp = fopen('php://memory', 'r+');
fputcsv($fp, []);
rewind($fp);
$row = fgetcsv($fp);
var_export($row);
echo "\n";
echo ($row === false ? 'false' : 'not-false'), "\n";
