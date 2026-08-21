<?php
/** Issue #33340 — SplFileObject::fputcsv thin AOT vs Zend. */
$path = sys_get_temp_dir() . "/spl_fputcsv_" . getmypid() . ".csv";
@unlink($path);
$f = new SplFileObject($path, "w+");
$n = $f->fputcsv(["a", "b"]);
$f->rewind();
$line = $f->fgets();
echo "n=" . $n . "\n";
echo "line=" . $line;
@unlink($path);
