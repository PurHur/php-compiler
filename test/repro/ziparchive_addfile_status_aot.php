<?php
// ZipArchive::addFile + getStatusString — AOT must match VM (#35449 leftover of #35424).
$path = sys_get_temp_dir() . '/phpc_zip_35449_' . getmypid() . '.zip';
$src = sys_get_temp_dir() . '/phpc_zip_35449_src_' . getmypid() . '.txt';
@unlink($path);
@unlink($src);
file_put_contents($src, 'from-file');
$z = new ZipArchive();
echo 'open=';
var_export($z->open($path, ZipArchive::CREATE));
echo "\n";
echo 'addFile=';
var_export($z->addFile($src, 'b.txt'));
echo "\n";
echo 'status=';
var_export($z->getStatusString());
echo "\n";
$z->close();
$z2 = new ZipArchive();
$z2->open($path);
echo $z2->getFromName('b.txt'), "\n";
$z2->close();
@unlink($path);
@unlink($src);
