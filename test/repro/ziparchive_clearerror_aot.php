<?php
// ZipArchive::clearError — AOT must clear $status like VM (#35531 leftover of #35527 / #20378).
$path = sys_get_temp_dir() . '/phpc_zip_35531_' . getmypid() . '.zip';
@unlink($path);
$z = new ZipArchive();
$z->open($path, ZipArchive::CREATE);
$z->addFromString('a.txt', 'x');
echo 'rename=';
var_export($z->renameName('missing', 'x'));
echo ' status1=', $z->status, "\n";
$z->clearError();
echo 'status2=', $z->status, "\n";
echo 'ret=';
var_export($z->clearError());
echo "\n";
$z->close();
@unlink($path);
