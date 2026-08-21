<?php
/**
 * #33332 — AOT SplFileObject::fread / fgetc via live __spl_fd (peer #33318/#33321).
 */
$p = sys_get_temp_dir().'/phpc_sfo_fread_33332_'.getmypid().'.txt';
file_put_contents($p, "line1\nAB");
$o = new SplFileObject($p);
echo 'fread='.var_export($o->fread(2), true)."\n";
echo 'fgetc='.var_export($o->fgetc(), true)."\n";
echo 'fread2='.var_export($o->fread(3), true)."\n";
@unlink($p);
