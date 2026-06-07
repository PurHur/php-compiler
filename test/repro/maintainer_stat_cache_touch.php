<?php
$p = '/tmp/phpc-stat-touch-' . getmypid();
@unlink($p);

$miss = fileatime($p);
$ok = touch($p);
$hit = fileatime($p);

echo 'miss=', var_export($miss, true), ' hit=', var_export($hit, true), "\n";
@unlink($p);
