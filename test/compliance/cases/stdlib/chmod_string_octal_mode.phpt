--TEST--
stdlib chmod() numeric-string mode is octal like Zend (ext/standard/filestat.c; #15081)
--FILE--
<?php

$f = sys_get_temp_dir() . '/phpc_chmod_octal_' . uniqid('', true) . '.tmp';
touch($f);
chmod($f, 0644);
$intMode = decoct(fileperms($f) & 0777);
chmod($f, '0644');
$strMode = decoct(fileperms($f) & 0777);
@unlink($f);
echo 'int_mode=', $intMode, ' str_mode=', $strMode, "\n";
echo $intMode === $strMode ? 'ok' : 'fail', "\n";
--EXPECT--
int_mode=644 str_mode=644
ok
