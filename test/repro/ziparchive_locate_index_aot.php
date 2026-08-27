<?php
// ZipArchive getNameIndex — AOT must match VM (#35440 leftover of #35437).
$path = sys_get_temp_dir() . '/phpc_zip_35440_' . getmypid() . '.zip';
@unlink($path);
$z = new ZipArchive();
$z->open($path, ZipArchive::CREATE);
$z->addFromString('a.txt', 'AAA');
echo 'nameIndex=';
var_export($z->getNameIndex(0));
echo "\n";
echo 'missName=';
var_export($z->getNameIndex(1));
echo "\n";
echo 'locate=';
var_export($z->locateName('a.txt'));
echo "\n";
echo 'fromIndex=';
var_export($z->getFromIndex(0));
echo "\n";
$z->close();
@unlink($path);
