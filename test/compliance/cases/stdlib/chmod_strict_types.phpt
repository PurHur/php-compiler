--TEST--
stdlib chmod() numeric-string permissions under declare(strict_types=1) (#17822, ext/standard/filestat.c)
--FILE--
<?php
declare(strict_types=1);

$f = sys_get_temp_dir() . '/phpc_chmod_strict_' . uniqid('', true) . '.tmp';
touch($f);
$ok = chmod($f, '0644');
$mode = fileperms($f) & 0777;
@unlink($f);

echo $ok ? 'ok' : 'fail', "\n";
echo $mode, "\n";
--EXPECT--
ok
132
