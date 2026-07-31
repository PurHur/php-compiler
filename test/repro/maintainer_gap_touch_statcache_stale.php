<?php
// Issue #25853 — after a positive filemtime hit, touch() must leave cache stale until clearstatcache.
$p = sys_get_temp_dir() . '/phpc-touch-statcache-' . getmypid();
@unlink($p);
file_put_contents($p, 'x');

$m1 = filemtime($p);
sleep(1);
touch($p);
$m2 = filemtime($p);
clearstatcache(true, $p);
$m3 = filemtime($p);

echo ($m2 === $m1 ? 'stale' : 'fresh'), '|', $m1, '|', $m2, '|', $m3, "\n";
@unlink($p);
