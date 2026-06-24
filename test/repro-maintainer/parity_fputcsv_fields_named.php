<?php

/** Issue #11113 — fputcsv($fp, fields:, separator:) named parameters. */

$path = sys_get_temp_dir() . '/fputcsv_named_' . getmypid() . '.csv';
$fp = fopen($path, 'w');
fputcsv($fp, fields: ['a', 'b'], separator: ',');
fclose($fp);
echo file_get_contents($path);
unlink($path);
