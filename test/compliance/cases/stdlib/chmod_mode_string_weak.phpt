--TEST--
stdlib chmod()/mkdir() numeric-string mode coercion without strict_types (#4207, ext/standard/filestat.c)
--FILE--
<?php

$f = sys_get_temp_dir() . '/phpc_chmod_mode_weak_' . uniqid('', true) . '.tmp';
touch($f);
$ok = chmod($f, '0644');
$mode = decoct(fileperms($f) & 0777);
@unlink($f);

$d = sys_get_temp_dir() . '/phpc_mkdir_mode_weak_' . uniqid('', true);
$mk = @mkdir($d, '0755');
$dmode = is_dir($d) ? decoct(fileperms($d) & 0777) : 'missing';
@rmdir($d);

echo $ok ? 'chmod_ok' : 'chmod_fail', "\n";
echo $mode, "\n";
echo $mk ? 'mkdir_ok' : 'mkdir_fail', "\n";
echo $dmode, "\n";
--EXPECT--
chmod_ok
644
mkdir_ok
755
