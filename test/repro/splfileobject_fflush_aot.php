<?php
/** Issue #33354 — SplFileObject::fflush thin AOT vs Zend. */
$path = sys_get_temp_dir() . "/spl_fflush_" . getmypid() . ".txt";
@unlink($path);
$f = new SplFileObject($path, "w+");
$n = $f->fwrite("hi");
$fl = $f->fflush();
$f->rewind();
$got = $f->fread(10);
echo "n=$n flush=";
var_export($fl);
echo " got=";
var_export($got);
echo "\n";
@unlink($path);
