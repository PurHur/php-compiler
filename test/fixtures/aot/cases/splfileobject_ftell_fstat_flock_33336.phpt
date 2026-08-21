--TEST--
AOT SplFileObject ftell/flock via live __spl_fd (#33336)
--FILE--
<?php
$p = sys_get_temp_dir().'/phpc_sfo_33336_fix_'.getmypid().'.txt';
file_put_contents($p, "line1\nline2\n");
$o = new SplFileObject($p);
$o->fgets();
echo $o->ftell(), "\n";
echo $o->flock(LOCK_SH) ? "locked\n" : "nolock\n";
$o->flock(LOCK_UN);
@unlink($p);
--EXPECT--
6
locked
