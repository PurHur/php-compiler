<?php
/**
 * #33371 — AOT SplFileObject::setCsvControl/getCsvControl (peer #33346 fgetcsv).
 * Avoid var_export(array) — thin AOT lacks Runtime->vm (#26855). Use json_encode.
 */
$p = sys_get_temp_dir().'/phpc_sfo_33371_'.getmypid().'.csv';
file_put_contents($p, "a;b\n");
$o = new SplFileObject($p, 'r');
$o->setCsvControl(';', '"', '\\');
$c = $o->getCsvControl();
echo 'ctrl=', json_encode($c), "\n";
$row = $o->fgetcsv();
echo 'row=', json_encode($row), "\n";
@unlink($p);
