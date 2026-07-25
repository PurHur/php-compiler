<?php
/** Repro #22841 — positive filesize cache retained until clearstatcache. */
$f = tempnam(sys_get_temp_dir(), 'phpc');
file_put_contents($f, 'x');
clearstatcache(true, $f);
echo 'sz1=', filesize($f), "\n";
file_put_contents($f, 'hello');
echo 'sz2_noclear=', filesize($f), "\n";
clearstatcache(true, $f);
echo 'sz3_clear=', filesize($f), "\n";
unlink($f);
