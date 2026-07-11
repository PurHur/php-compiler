<?php

declare(strict_types=1);

$path = tempnam(sys_get_temp_dir(), 'fpc_null_');
$n = file_put_contents($path, null);
$size = filesize($path);
unlink($path);
var_export($n);
echo "\n";
var_export($size);
echo "\n";
echo "ok\n";
