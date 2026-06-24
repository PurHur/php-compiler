<?php

$path = sys_get_temp_dir().'/phpc_fsync_'.getmypid().'.txt';
file_put_contents($path, 'x');
$h = fopen($path, 'r+');
var_export(fsync($h));
echo "\n";
var_export(fdatasync($h));
echo "\n";
fclose($h);
@unlink($path);
