--TEST--
AOT SplFileObject::fgetcsv via live __spl_fd (#33346)
--FILE--
<?php
$p = sys_get_temp_dir().'/phpc_sfo_33346_'.getmypid().'.csv';
file_put_contents($p, "a,b\n");
$o = new SplFileObject($p);
var_export($o->fgetcsv());
echo "\n";
@unlink($p);
--EXPECT--
array (
  0 => 'a',
  1 => 'b',
)
