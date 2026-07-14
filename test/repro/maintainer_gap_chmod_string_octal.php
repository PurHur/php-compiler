<?php

$fInt = sys_get_temp_dir().'/phpc_chmod_int_'.uniqid('', true).'.tmp';
touch($fInt);
chmod($fInt, 0644);
$intMode = decoct(fileperms($fInt) & 0777);
@unlink($fInt);

$fStr = sys_get_temp_dir().'/phpc_chmod_str_'.uniqid('', true).'.tmp';
touch($fStr);
chmod($fStr, '0644');
$strMode = decoct(fileperms($fStr) & 0777);
@unlink($fStr);

echo 'int_mode=', $intMode, ' str_mode=', $strMode, "\n";
if ('644' !== $intMode || '204' !== $strMode) {
    exit(1);
}
