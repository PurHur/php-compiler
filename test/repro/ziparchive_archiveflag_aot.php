<?php
// ZipArchive::setArchiveFlag / getArchiveFlag — AOT must match VM (#35522 leftover of #21831).
$path = sys_get_temp_dir() . '/phpc_zip_af_' . getmypid() . '.zip';
@unlink($path);
$z = new ZipArchive();
$z->open($path, ZipArchive::CREATE);
$z->addFromString('a.txt', 'A');
echo 'set=';
var_export($z->setArchiveFlag(ZipArchive::AFL_RDONLY, 1));
echo "\n";
echo 'get=';
var_export($z->getArchiveFlag(ZipArchive::AFL_RDONLY));
echo "\n";
echo 'clear=';
var_export($z->setArchiveFlag(ZipArchive::AFL_RDONLY, 0));
echo "\n";
echo 'get2=';
var_export($z->getArchiveFlag(ZipArchive::AFL_RDONLY));
echo "\n";
$z->close();
@unlink($path);
