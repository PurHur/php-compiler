<?php
// Issue #25308 — touch() must invalidate VmStatCache so filemtime() sees new mtime.
$p = sys_get_temp_dir() . '/phpc-touch-statcache-' . getmypid();
@unlink($p);
touch($p);

touch($p, 100);
$afterFirst = filemtime($p);

clearstatcache(true, $p);
touch($p, 200);
$afterSecond = filemtime($p);

echo 'after_first=', $afterFirst, ' after_second=', $afterSecond, "\n";
@unlink($p);
