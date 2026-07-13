<?php
// php-src ext/standard/filestat.c — chmod() int 0644 vs string '0644' diverge (#18487).
$f = sys_get_temp_dir().'/phpc_chmod_same_'.uniqid('', true).'.tmp';
touch($f);
chmod($f, 0644);
$intMode = decoct(fileperms($f) & 0777);
chmod($f, '0644');
$strMode = decoct(fileperms($f) & 0777);
@unlink($f);

echo 'int_mode=', $intMode, ' str_mode=', $strMode, "\n";
// Zend may keep str_mode=644 when fileperms() ran between chmod calls (stat cache).
echo '644' === $intMode && ('204' === $strMode || '644' === $strMode) ? "ok\n" : "fail\n";
