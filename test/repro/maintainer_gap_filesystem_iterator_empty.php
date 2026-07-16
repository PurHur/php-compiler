<?php
$dir = sys_get_temp_dir() . '/phpc_fsi_foreach_' . getmypid();
@mkdir($dir);
$p = $dir . '/entry.txt';
file_put_contents($p, 'x');
$names = [];
foreach (new FilesystemIterator($dir) as $f) {
    $names[] = $f->getFilename();
}
sort($names);
@unlink($p);
@rmdir($dir);
echo implode(',', $names), "\n";
