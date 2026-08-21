--TEST--
AOT SplFileObject::ftruncate via live __spl_fd (#33348)
--FILE--
<?php
$p = sys_get_temp_dir().'/phpc_sfo_33348_fix_'.getmypid().'.txt';
file_put_contents($p, 'abcdefgh');
$o = new SplFileObject($p, 'r+');
echo $o->ftruncate(3) ? "ok\n" : "fail\n";
echo file_get_contents($p), "\n";
@unlink($p);
--EXPECT--
ok
abc
