<?php
// Repro #33378 — AOT SplFileObject setMaxLineLen must truncate fgets (php-src max_line_len+1).
$path = sys_get_temp_dir().'/phpc_sfo_maxlen_'.getmypid().'.txt';
file_put_contents($path, "abcdefghij\n");
$f = new SplFileObject($path);
$f->setMaxLineLen(4);
echo 'max=', $f->getMaxLineLen(), "\n";
echo 'line=', var_export($f->fgets(), true), "\n";
@unlink($path);
