<?php
/** Issue #33400 — SplFileObject::fwrite/fputcsv must flush so path reads match Zend. */
$base = sys_get_temp_dir() . '/spl_fw_flush_' . getmypid();
$wpath = $base . '_w.txt';
$cpath = $base . '_c.csv';
@unlink($wpath);
@unlink($cpath);

$f = new SplFileObject($wpath, 'w+');
$n = $f->fwrite('hi');
echo "alive=[" . file_get_contents($wpath) . "] n=$n\n";
$f = null;
echo "after=[" . file_get_contents($wpath) . "]\n";

$f = new SplFileObject($cpath, 'w+');
$f->fputcsv(['a', 'b']);
$f = null;
echo "csv=[" . str_replace("\n", '\n', file_get_contents($cpath)) . "]\n";

@unlink($wpath);
@unlink($cpath);
