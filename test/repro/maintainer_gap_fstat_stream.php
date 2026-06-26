<?php

declare(strict_types=1);

$handle = fopen('php://memory', 'r+');
fwrite($handle, 'abc');
rewind($handle);
$stat = fstat($handle);
var_export(is_array($stat));
echo "\n";
var_export($stat['size'] ?? 'missing');
echo "\n";
fclose($handle);
