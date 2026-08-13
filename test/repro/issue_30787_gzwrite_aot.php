<?php
$z = gzopen('/tmp/gzwrite_aot_30787.gz', 'w');
$n = gzwrite($z, 'hello');
gzclose($z);
echo $n, "\n";
$z2 = gzopen('/tmp/gzputs_aot_30787.gz', 'w');
$n2 = gzputs($z2, 'hi');
gzclose($z2);
echo $n2, "\n";
$r = gzopen('/tmp/gzwrite_aot_30787.gz', 'r');
echo gzread($r, 16), "\n";
gzclose($r);
