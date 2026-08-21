--TEST--
AOT SplFileObject::fgetcsv via live __spl_fd (#33346)
--FILE--
<?php
$p = sys_get_temp_dir().'/phpc_sfo_33346_fix_'.getmypid().'.csv';
file_put_contents($p, "a,b\n");
$o = new SplFileObject($p, 'r');
echo json_encode($o->fgetcsv()), "\n";
@unlink($p);
--EXPECT--
["a","b"]
