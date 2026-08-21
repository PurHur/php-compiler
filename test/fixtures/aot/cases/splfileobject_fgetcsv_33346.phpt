--TEST--
AOT SplFileObject::fgetcsv via live __spl_fd (#33346)
--FILE--
<?php
$p = sys_get_temp_dir().'/phpc_sfo_33346_'.getmypid().'.csv';
file_put_contents($p, "a,b\n");
$o = new SplFileObject($p);
$row = $o->fgetcsv();
echo \is_array($row) ? ($row[0].'|'.$row[1]) : 'not-array';
echo "\n";
@unlink($p);
--EXPECT--
a|b
