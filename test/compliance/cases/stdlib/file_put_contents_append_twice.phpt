--TEST--
stdlib file_put_contents FILE_APPEND twice — mid-read sees concatenated bytes (#24339)
--FILE--
<?php
$p = sys_get_temp_dir() . '/phpc_fpc_append_' . getmypid() . '.txt';
@unlink($p);
$n1 = file_put_contents($p, 'a', FILE_APPEND);
$c1 = @file_get_contents($p);
$n2 = file_put_contents($p, 'b', FILE_APPEND);
$c2 = @file_get_contents($p);
echo "n1=$n1 c1=", json_encode($c1), " n2=$n2 c2=", json_encode($c2), "\n";
@unlink($p);
--EXPECT--
n1=1 c1="a" n2=1 c2="ab"
