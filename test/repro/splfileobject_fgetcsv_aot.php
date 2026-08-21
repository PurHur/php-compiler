<?php
// Repro #33346 — AOT SplFileObject::fgetcsv via live __spl_fd (peer #33334 / #33340).
// Avoid var_export(array) / implode(array) — thin AOT aborts without Runtime->vm (#26855).
$path = sys_get_temp_dir().'/phpc_sfo_fgetcsv_'.getmypid().'.csv';
file_put_contents($path, "a,b\n");
$f = new SplFileObject($path);
$row = $f->fgetcsv();
if (!\is_array($row)) {
    echo 'not-array';
} else {
    echo $row[0], '|', $row[1];
}
echo "\n";
@unlink($path);
