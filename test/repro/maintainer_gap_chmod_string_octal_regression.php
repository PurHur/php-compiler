<?php
// php-src ext/standard/filestat.c — chmod() numeric-string mode uses zend_strtol(..., 0) (#18487).
$f = sys_get_temp_dir().'/phpc_chmod_str_'.uniqid('', true).'.tmp';
touch($f);
chmod($f, 0644);
$intMode = decoct(fileperms($f) & 0777);
chmod($f, '0644');
$strMode = decoct(fileperms($f) & 0777);
@unlink($f);

echo 'int_mode=', $intMode, ' str_mode=', $strMode, "\n";
echo $intMode === $strMode ? "ok\n" : "fail\n";
