<?php
// ZipArchive renameName / deleteName — AOT must match VM (#35450 leftover of #35424).
$path = sys_get_temp_dir() . '/phpc_zip_35450_' . getmypid() . '.zip';
@unlink($path);
$z = new ZipArchive();
$z->open($path, ZipArchive::CREATE);
$z->addFromString('a.txt', 'AAA');
echo 'rename=';
var_export($z->renameName('a.txt', 'b.txt'));
echo "\n";
echo 'fromB=';
var_export($z->getFromName('b.txt'));
echo "\n";
echo 'fromA=';
var_export($z->getFromName('a.txt'));
echo "\n";
echo 'delete=';
var_export($z->deleteName('b.txt'));
echo "\n";
echo 'afterDel=';
var_export($z->getFromName('b.txt'));
echo "\n";
echo 'numFiles=';
var_export($z->numFiles);
echo "\n";
$z->close();
@unlink($path);
