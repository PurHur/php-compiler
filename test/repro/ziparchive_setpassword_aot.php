<?php
// ZipArchive::setPassword — AOT must match VM (#35500 leftover of #35496 / #19873).
$zipPath = sys_get_temp_dir() . '/phpc_zip_35500_' . getmypid() . '.zip';
@unlink($zipPath);
$z = new ZipArchive();
$z->open($zipPath, ZipArchive::CREATE);
echo 'set=';
var_export($z->setPassword('x'));
echo "\n";
echo 'empty=';
var_export($z->setPassword(''));
echo "\n";
$z->close();
@unlink($zipPath);
