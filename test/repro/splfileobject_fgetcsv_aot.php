<?php
// Repro #33346 — AOT SplFileObject::fgetcsv via live __spl_fd (peer #33334 / #33340).
$path = sys_get_temp_dir().'/phpc_sfo_fgetcsv_'.getmypid().'.csv';
file_put_contents($path, "a,\"b,c\",d\n");
$f = new SplFileObject($path);
var_export($f->fgetcsv());
echo "\n";
@unlink($path);
