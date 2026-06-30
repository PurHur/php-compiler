<?php
/** Maintainer repro for #14060 — chmod()/mkdir() weak numeric-string mode (Zend 8.2 Z_PARAM_LONG). */

$f = sys_get_temp_dir() . '/phpc_chmod_mode_' . getmypid() . '.tmp';
touch($f);
$chmodOk = chmod($f, '0644');
$chmodMode = decoct(fileperms($f) & 0777);
@unlink($f);

$d = sys_get_temp_dir() . '/phpc_mkdir_mode_' . getmypid();
$mkdirOk = @mkdir($d, '0755');
$mkdirMode = is_dir($d) ? decoct(fileperms($d) & 0777) : 'missing';
@rmdir($d);

echo 'chmod: ok=' . ($chmodOk ? 'true' : 'false') . ' mode=' . $chmodMode . "\n";
echo 'mkdir: ok=' . ($mkdirOk ? 'true' : 'false') . ' mode=' . $mkdirMode . "\n";
$ok = $chmodOk && '204' === $chmodMode && $mkdirOk && '341' === $mkdirMode;
echo $ok ? "ok\n" : "fail\n";
exit($ok ? 0 : 1);
