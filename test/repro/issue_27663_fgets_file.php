<?php
$path = sys_get_temp_dir() . '/fgets27663.txt';
file_put_contents($path, "1 2 3\n");
$f = fopen($path, "r");
$line = fgets($f);
fclose($f);
@unlink($path);
echo "[$line]\n";
