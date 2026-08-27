<?php
// ZipArchive::replaceFile — AOT must match VM (#35496 leftover of #35489).
$zipPath = sys_get_temp_dir() . '/phpc_zip_35496_' . getmypid() . '.zip';
$srcPath = sys_get_temp_dir() . '/phpc_zip_35496_src_' . getmypid() . '.txt';
@unlink($zipPath);
@unlink($srcPath);
file_put_contents($srcPath, 'ZZ');
$z = new ZipArchive();
$z->open($zipPath, ZipArchive::CREATE);
$z->addFromString('a.txt', 'A');
echo 'rpl=';
var_export($z->replaceFile($srcPath, 0));
echo "\n";
echo 'get=';
var_export($z->getFromName('a.txt'));
echo "\n";
$z->close();
@unlink($zipPath);
@unlink($srcPath);
