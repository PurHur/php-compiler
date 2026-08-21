<?php
/** Issue #33360 — SplFileObject::fpassthru thin AOT vs Zend. */
$path = sys_get_temp_dir() . "/spl_fpassthru_" . getmypid() . ".txt";
@unlink($path);
file_put_contents($path, "hi");
$f = new SplFileObject($path);
$n = $f->fpassthru();
echo "n=";
var_export($n);
echo "\n";
@unlink($path);
