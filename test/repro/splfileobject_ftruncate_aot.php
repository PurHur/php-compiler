<?php
/**
 * #33348 — AOT SplFileObject::ftruncate via live __spl_fd (peer #33336 / #33155).
 * Assert truncate return + on-disk contents (avoid filesize() AOT drift).
 */
$p = sys_get_temp_dir().'/phpc_sfo_33348_'.getmypid().'.txt';
file_put_contents($p, 'abcdefgh');
$o = new SplFileObject($p, 'r+');
$r = $o->ftruncate(3);
echo 'r=', var_export($r, true), ' content=', var_export(file_get_contents($p), true), "\n";
@unlink($p);
