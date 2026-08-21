--TEST--
AOT SplFileObject fread/fgetc match Zend (#33332)
--FILE--
<?php
$p = sys_get_temp_dir().'/phpc_sfo_33332_phpt_'.getmypid().'.txt';
file_put_contents($p, "line1\nXY");
$o = new SplFileObject($p);
echo var_export($o->fread(2), true), "\n";
$o2 = new SplFileObject($p);
echo var_export($o2->fgetc(), true), "\n";
@unlink($p);
--EXPECT--
'li'
'l'
