<?php

/** Issue #11112 — fwrite($fp, data: 'abc') named second parameter. */

$path = sys_get_temp_dir() . '/fwrite_named_' . getmypid() . '.txt';
$fp = fopen($path, 'w');
$n = fwrite($fp, data: 'abc');
fclose($fp);
var_export([$n, file_get_contents($path)]);
echo "\n";
unlink($path);
