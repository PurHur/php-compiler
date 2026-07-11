--TEST--
stdlib chmod()/mkdir() decimal numeric-string permissions (#17819, #17860, ext/standard/filestat.c)
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
echo 132 === $chmodMode && '341' === $mkdirMode ? 'ok' : 'fail', "\n";
--EXPECT--
chmod=132 mkdir=341
ok
