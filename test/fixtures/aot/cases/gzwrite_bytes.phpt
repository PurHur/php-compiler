--TEST--
AOT: gzwrite/gzputs return byte counts (#30787)
--FILE--
<?php
$path = sys_get_temp_dir().'/phpc_gzwrite_30787_'.getmypid().'.gz';
$z = gzopen($path, 'w');
$n = gzwrite($z, 'hello');
gzclose($z);
echo $n, "\n";
$z2 = gzopen($path, 'w');
$n2 = gzputs($z2, 'hi');
gzclose($z2);
echo $n2, "\n";
@unlink($path);
?>
--EXPECT--
5
2
