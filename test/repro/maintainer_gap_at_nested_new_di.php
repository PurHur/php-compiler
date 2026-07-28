<?php
$dir = sys_get_temp_dir() . '/phpc_atndi_' . getmypid();
@mkdir($dir);
file_put_contents($dir . '/x.txt', '1');
$it = new IteratorIterator(new DirectoryIterator($dir));
$n = 0;
foreach ($it as $f) { $n++; }
echo "count=$n\n";
unlink($dir . '/x.txt');
rmdir($dir);
