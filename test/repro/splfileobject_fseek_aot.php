<?php
/**
 * #33347 — AOT SplFileObject::fseek via live __spl_fd (peer #33336 / #33319 rewind).
 * Zend: rc=0 pos=3 ch=d on "abcdefgh".
 */
$p = sys_get_temp_dir().'/phpc_sfo_33347_'.getmypid().'.txt';
file_put_contents($p, 'abcdefgh');
$o = new SplFileObject($p, 'r');
$rc = $o->fseek(3);
$pos = $o->ftell();
$ch = $o->fgetc();
echo 'rc=', var_export($rc, true), ' pos=', var_export($pos, true), ' ch=', var_export($ch, true), "\n";
@unlink($p);
