<?php
// ZipArchive deleteIndex — AOT must match VM (#35455 leftover of #35450).
$path = sys_get_temp_dir() . '/phpc_zip_35455_' . getmypid() . '.zip';
@unlink($path);
$z = new ZipArchive();
$z->open($path, ZipArchive::CREATE);
$z->addFromString('a.txt', 'hi');
echo 'delete=';
var_export($z->deleteIndex(0));
echo "\n";
echo 'fromA=';
var_export($z->getFromName('a.txt'));
echo "\n";
echo 'numFiles=';
var_export($z->numFiles);
echo "\n";
echo 'badIdx=';
var_export($z->deleteIndex(1));
echo "\n";
$z->close();
@unlink($path);
