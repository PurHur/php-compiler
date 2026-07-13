--TEST--
JIT: chmod() numeric-string mode uses zend_strtol auto-base (#18487, ext/standard/filestat.c)
--FILE--
<?php

$fInt = sys_get_temp_dir() . '/phpc_chmod_jit_int_' . uniqid('', true) . '.tmp';
touch($fInt);
chmod($fInt, 0644);
$intMode = decoct(fileperms($fInt) & 0777);
@unlink($fInt);

$fStr = sys_get_temp_dir() . '/phpc_chmod_jit_str_' . uniqid('', true) . '.tmp';
touch($fStr);
chmod($fStr, '0644');
$strMode = decoct(fileperms($fStr) & 0777);
@unlink($fStr);

echo 'int_mode=', $intMode, ' str_mode=', $strMode, "\n";
echo $intMode === '644' && $strMode === '644' ? 'ok' : 'fail', "\n";
--EXPECT--
int_mode=644 str_mode=644
ok
