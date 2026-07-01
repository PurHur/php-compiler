--TEST--
AOT: gzseek()/gztell()/gzrewind() on gzip read stream (#14585)
--FILE--
<?php
$path = sys_get_temp_dir() . '/phpc_gzseek_aot_' . getmypid() . '.gz';
$w = gzopen($path, 'w9');
gzwrite($w, 'abcdef');
gzclose($w);
$r = gzopen($path, 'r');
gzseek($r, 3);
echo gztell($r), ':', gzread($r, 3), "\n";
gzrewind($r);
echo gztell($r), ':', gzread($r, 3), "\n";
gzclose($r);
@unlink($path);
--EXPECT--
3:def
0:abc
