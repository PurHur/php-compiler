<?php
/** Issue #33347 — SplFileObject::fseek thin AOT vs Zend. */
$path = sys_get_temp_dir() . "/spl_fseek_" . getmypid() . ".txt";
file_put_contents($path, "abcdefgh");
$f = new SplFileObject($path, "r");
$rc = $f->fseek(3);
echo "rc=";
var_export($rc);
echo " pos=" . $f->ftell() . " ch=" . $f->fgetc() . "\n";
@unlink($path);
