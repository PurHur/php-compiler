--TEST--
AOT: SplFileObject fread/fgetc read bytes (#33332)
--FILE--
<?php
$p = sys_get_temp_dir() . '/phpc_sfo_fread_33332_fx.txt';
file_put_contents($p, "line1\nAB");
$o = new SplFileObject($p);
echo 'fread=' . var_export($o->fread(2), true) . "\n";
echo 'fgetc=' . var_export($o->fgetc(), true) . "\n";
echo 'fread2=' . var_export($o->fread(3), true) . "\n";
@unlink($p);
?>
--EXPECT--
fread='li'
fgetc='n'
fread2='e1
'
