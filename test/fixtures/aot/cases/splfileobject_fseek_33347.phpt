--TEST--
AOT SplFileObject::fseek via live __spl_fd (#33347)
--FILE--
<?php
$p = sys_get_temp_dir().'/phpc_sfo_33347_fix_'.getmypid().'.txt';
file_put_contents($p, 'abcdefgh');
$o = new SplFileObject($p, 'r');
echo $o->fseek(3), "\n";
echo $o->ftell(), "\n";
echo $o->fgetc(), "\n";
@unlink($p);
--EXPECT--
0
3
d
