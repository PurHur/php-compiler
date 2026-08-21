<?php
/**
 * #33336 — AOT SplFileObject ftell / flock via live __spl_fd (peer #33332).
 * fstat array|false proxy deferred (HT vs value-box return typing).
 */
$p = sys_get_temp_dir().'/phpc_sfo_33336_'.getmypid().'.txt';
file_put_contents($p, "line1\nline2\n");
$o = new SplFileObject($p);
echo 'fgets=', var_export($o->fgets(), true), "\n";
echo 'ftell=', var_export($o->ftell(), true), "\n";
echo 'flock=', var_export($o->flock(LOCK_SH), true), "\n";
$o->flock(LOCK_UN);
@unlink($p);
