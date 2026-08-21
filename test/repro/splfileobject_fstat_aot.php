<?php
// Repro #33359 — AOT SplFileObject::fstat via live __spl_fd (peer #33336).
$path = sys_get_temp_dir().'/phpc_sfo_fstat_'.getmypid().'.txt';
file_put_contents($path, 'hi');
$f = new SplFileObject($path);
$s = $f->fstat();
echo is_array($s) ? ('size='.$s['size']) : 'not-array';
echo "\n";
@unlink($path);
