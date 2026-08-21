<?php
// Repro #33369 — AOT SplFileObject getCsvControl/setCsvControl.
$path = sys_get_temp_dir().'/phpc_sfo_csvctl_'.getmypid().'.txt';
file_put_contents($path, "a,b\n");
$o = new SplFileObject($path);
echo 'ctl=', json_encode($o->getCsvControl()), "\n";
$o->setCsvControl(';', '"', '\\');
echo 'ctl2=', json_encode($o->getCsvControl()), "\n";
@unlink($path);
