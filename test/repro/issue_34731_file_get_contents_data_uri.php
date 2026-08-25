<?php
/**
 * #34731 — AOT file_get_contents/readfile must decode data:// (php_data_wrapper.c).
 */
echo 'plain:';
var_dump(file_get_contents('data://text/plain,hi'));

echo 'base64:';
var_dump(file_get_contents('data://text/plain;base64,aGk='));

$tmp = sys_get_temp_dir().'/phpc_fgc_34731_'.getmypid().'.txt';
file_put_contents($tmp, 'fs');
echo 'fs:';
var_dump(file_get_contents($tmp));
@unlink($tmp);

echo 'readfile:';
echo readfile('data://text/plain,hi'), "\n";
