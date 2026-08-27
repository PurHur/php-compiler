<?php
// ZipArchive entry comments — AOT must match VM (#35486 leftover of #35476).
$path = sys_get_temp_dir() . '/phpc_zip_35486_' . getmypid() . '.zip';
@unlink($path);
$z = new ZipArchive();
$z->open($path, ZipArchive::CREATE);
$z->addFromString('a.txt', 'AAA');
echo 'setn=';
var_export($z->setCommentName('a.txt', 'ec'));
echo "\n";
echo 'getn=';
var_export($z->getCommentName('a.txt'));
echo "\n";
echo 'seti=';
var_export($z->setCommentIndex(0, 'ei'));
echo "\n";
echo 'geti=';
var_export($z->getCommentIndex(0));
echo "\n";
$z->close();
@unlink($path);
