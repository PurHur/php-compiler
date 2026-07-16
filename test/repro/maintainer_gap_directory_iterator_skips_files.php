<?php
$dir = sys_get_temp_dir() . '/phpc_diriter_foreach_' . getmypid();
@mkdir($dir);
$p = $dir . '/entry.txt';
file_put_contents($p, 'x');
$names = [];
foreach (new DirectoryIterator($dir) as $f) {
    if ($f->isDot()) {
        continue;
    }
    $names[] = (string) $f;
}
sort($names);
echo $names === ['entry.txt'] ? "ok\n" : ('fail: ' . var_export($names, true) . "\n");
@unlink($p);
@rmdir($dir);
