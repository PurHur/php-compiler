<?php
/**
 * #33346 — AOT SplFileObject::fgetcsv via live __spl_fd (peer #33340 / #33334).
 * Avoid var_export(array) — thin AOT lacks Runtime->vm (#26855). Use json_encode.
 */
$p = sys_get_temp_dir().'/phpc_sfo_33346_'.getmypid().'.csv';
file_put_contents($p, "a,b\n");
$o = new SplFileObject($p, 'r');
$row = $o->fgetcsv();
echo 'row=', json_encode($row), "\n";
@unlink($p);
