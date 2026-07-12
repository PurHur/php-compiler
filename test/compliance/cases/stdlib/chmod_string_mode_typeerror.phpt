--TEST--
stdlib chmod() string mode under declare(strict_types=1) — internal builtin ignores caller strict (#17822)
--FILE--
<?php
declare(strict_types=1);

$f = sys_get_temp_dir() . '/phpc_chmod_str_mode_' . uniqid('', true) . '.tmp';
touch($f);
$ok = chmod($f, '0644');
$mode = decoct(fileperms($f) & 0777);
@unlink($f);

echo $ok ? 'ok' : 'fail', "\n";
echo $mode, "\n";
--EXPECT--
ok
204
