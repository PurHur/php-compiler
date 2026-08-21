<?php
/**
 * #33347 — AOT SplFileObject::fseek via live __spl_fd (peer #33336 / #33340).
 */
$p = sys_get_temp_dir().'/phpc_sfo_33347_'.getmypid().'.txt';
file_put_contents($p, 'abcdefgh');
$o = new SplFileObject($p, 'r');
$rc = $o->fseek(3);
echo 'rc=', var_export($rc, true), ' pos=', var_export($o->ftell(), true),
    ' ch=', var_export($o->fgetc(), true), "\n";
@unlink($p);
