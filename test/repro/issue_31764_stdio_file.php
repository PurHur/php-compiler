<?php
$f = fopen('php://temp', 'w+');
fwrite($f, 'ok');
rewind($f);
echo fread($f, 2);
fclose($f);
echo "\n";
$path = sys_get_temp_dir() . '/phpc_31764_' . getmypid() . '.txt';
file_put_contents($path, 'ab');
echo file_get_contents($path);
@unlink($path);
echo "\n";
