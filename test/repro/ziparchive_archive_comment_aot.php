<?php
// ZipArchive setArchiveComment / getArchiveComment — AOT must match VM (#35476 leftover of #35472).
$path = sys_get_temp_dir() . '/phpc_zip_35476_' . getmypid() . '.zip';
@unlink($path);
$z = new ZipArchive();
$z->open($path, ZipArchive::CREATE);
$z->addFromString('a.txt', 'AAA');
echo 'empty=';
var_export($z->getArchiveComment());
echo "\n";
echo 'set=';
var_export($z->setArchiveComment('c'));
echo "\n";
echo 'get=';
var_export($z->getArchiveComment());
echo "\n";
$z->close();
$z2 = new ZipArchive();
$z2->open($path);
echo 'reopen=';
var_export($z2->getArchiveComment());
echo "\n";
$z2->close();
@unlink($path);
