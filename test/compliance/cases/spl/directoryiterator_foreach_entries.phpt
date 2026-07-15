--TEST--
DirectoryIterator foreach yields non-dot entries (#19088, ext/spl/spl_directory.c)
--FILE--
<?php
$dir = sys_get_temp_dir() . '/phpc_diriter_foreach_' . getmypid();
@mkdir($dir);
$p = $dir . '/entry.txt';
file_put_contents($p, 'x');

$names = [];
foreach (new DirectoryIterator($dir) as $f) {
    if (!$f->isDot()) {
        $names[] = $f->getFilename();
    }
}
sort($names);

@unlink($p);
@rmdir($dir);

echo implode(',', $names), "\n";
?>
--EXPECT--
entry.txt
