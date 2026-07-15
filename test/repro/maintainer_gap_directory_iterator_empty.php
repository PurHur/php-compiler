<?php
/**
 * Issue #19088 — DirectoryIterator foreach must yield non-dot filenames.
 */
$dir = sys_get_temp_dir() . '/phpc_diriter_empty_' . getmypid();
@mkdir($dir);
$p = $dir . '/entry.txt';
$written = file_put_contents($p, 'x');
$existsBefore = file_exists($p);
$scanBefore = scandir($dir);

$names = [];
foreach (new DirectoryIterator($dir) as $f) {
    if (!$f->isDot()) {
        $names[] = $f->getFilename();
    }
}
sort($names);

@unlink($p);
@rmdir($dir);

if ($names === ['entry.txt']) {
    echo "ok\n";
    exit(0);
}
echo 'fail: got ' . json_encode($names) . ' written=' . var_export($written, true) . ' existsBefore=' . var_export($existsBefore, true) . ' scan=' . json_encode($scanBefore) . ' dir=' . $dir . "\n";
exit(1);
