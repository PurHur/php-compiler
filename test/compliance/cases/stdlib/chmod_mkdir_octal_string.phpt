--TEST--
stdlib chmod()/mkdir() octal numeric-string permissions (#18887, ext/standard/filestat.c)
--FILE--
<?php

$f = sys_get_temp_dir() . '/phpc_chmod_octal_' . uniqid('', true) . '.tmp';
touch($f);
chmod($f, '0644');
$chmodMode = fileperms($f) & 0777;
@unlink($f);

$d = sys_get_temp_dir() . '/phpc_mkdir_octal_' . uniqid('', true);
mkdir($d, '0755', true);
$mkdirMode = is_dir($d) ? decoct(fileperms($d) & 0777) : '0';
@rmdir($d);

echo 'chmod=', $chmodMode, ' mkdir=', $mkdirMode, "\n";
echo 420 === $chmodMode && '755' === $mkdirMode ? 'ok' : 'fail', "\n";
--EXPECT--
chmod=420 mkdir=755
ok
