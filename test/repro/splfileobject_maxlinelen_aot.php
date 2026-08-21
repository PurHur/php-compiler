<?php
// Repro #33377 — AOT SplFileObject getMaxLineLen/setMaxLineLen.
$path = sys_get_temp_dir().'/phpc_sfo_mll_'.getmypid().'.txt';
file_put_contents($path, "hello\nworld\n");
$o = new SplFileObject($path);
echo 'max=', var_export($o->getMaxLineLen(), true), "\n";
$o->setMaxLineLen(3);
echo 'max2=', var_export($o->getMaxLineLen(), true), "\n";
@unlink($path);
