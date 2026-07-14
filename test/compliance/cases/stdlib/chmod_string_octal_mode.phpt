--TEST--
stdlib chmod() int literal 0644 vs string '0644' mode agreement (#18887, ext/standard/filestat.c)
--FILE--
<?php

$fInt = sys_get_temp_dir() . '/phpc_chmod_int_' . uniqid('', true) . '.tmp';
touch($fInt);
chmod($fInt, 0644);
$intMode = decoct(fileperms($fInt) & 0777);
@unlink($fInt);

$fStr = sys_get_temp_dir() . '/phpc_chmod_str_' . uniqid('', true) . '.tmp';
touch($fStr);
chmod($fStr, '0644');
$strMode = decoct(fileperms($fStr) & 0777);
@unlink($fStr);

echo 'int_mode=', $intMode, ' str_mode=', $strMode, "\n";
echo $intMode === '644' && $strMode === '644' ? 'ok' : 'fail', "\n";
--EXPECT--
int_mode=644 str_mode=644
ok
