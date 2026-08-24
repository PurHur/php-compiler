<?php
// #34445 — AOT after Type::initialize Stat/StreamGlobals/Gz/Bz2/Csv lazy-link
$tmp = sys_get_temp_dir() . '/phpc_34445_' . getmypid();
@mkdir($tmp);
$file = $tmp . '/a.txt';
file_put_contents($file, "a,b\n");
echo file_exists($file) ? "exists\n" : "missing\n";
echo is_file($file) ? "isfile\n" : "notfile\n";
echo str_getcsv('x,y,z')[1], "\n";
$fh = fopen($file, 'r');
$row = fgetcsv($fh);
fclose($fh);
echo is_array($row) ? $row[0] . '|' . $row[1] . "\n" : "nofgetcsv\n";
@unlink($file);
@rmdir($tmp);
