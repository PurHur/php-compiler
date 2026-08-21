<?php
// Repro #33378 — AOT SplFileObject::setMaxLineLen/getMaxLineLen + fgets truncation.
$path = sys_get_temp_dir().'/phpc_sfo_maxlen_'.getmypid().'.txt';
file_put_contents($path, "abcdefghij\n");
$f = new SplFileObject($path);
echo 'max='.$f->getMaxLineLen()."\n";
$f->setMaxLineLen(4);
echo 'max2='.$f->getMaxLineLen()."\n";
echo 'line='.var_export($f->fgets(), true)."\n";
@unlink($path);
