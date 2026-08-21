<?php
/**
 * #33332 — AOT SplFileObject fread / fgetc via live __spl_fd (peer #33318/#33321).
 */
$p = sys_get_temp_dir().'/phpc_sfo_33332_'.getmypid().'.txt';
file_put_contents($p, "line1\nAB");
$o = new SplFileObject($p);
echo 'fread=', var_export($o->fread(2), true), "\n";
$o2 = new SplFileObject($p);
echo 'fgetc=', var_export($o2->fgetc(), true), "\n";
$o3 = new SplFileObject($p);
echo 'gcl=', var_export($o3->getCurrentLine(), true), "\n";
@unlink($p);
