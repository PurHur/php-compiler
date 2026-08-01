<?php
$r = new ReflectionFunction('symlink');
echo 'ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'NONE', "\n";
$dir = sys_get_temp_dir() . '/phpc_symlink_26323_' . getmypid();
@mkdir($dir);
$t = $dir . '/target.txt';
$l = $dir . '/link.txt';
file_put_contents($t, 'x');
@unlink($l);
$ok = symlink($t, $l);
echo 'ok=', var_export($ok, true), ' type=', gettype($ok), "\n";
@unlink($l);
@unlink($t);
@rmdir($dir);
