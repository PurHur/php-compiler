<?php
// ZipArchive set/getCommentName + set/getCommentIndex — AOT must match VM (#35486 leftover of #35476).
$path = sys_get_temp_dir() . '/phpc_zip_35486_' . getmypid() . '.zip';
@unlink($path);
$z = new ZipArchive();
$z->open($path, ZipArchive::CREATE);
$z->addFromString('a.txt', 'AAA');
echo 'setName=';
var_export($z->setCommentName('a.txt', 'ec'));
echo "\n";
echo 'getName=';
var_export($z->getCommentName('a.txt'));
echo "\n";
echo 'setIdx=';
var_export($z->setCommentIndex(0, 'ei'));
echo "\n";
echo 'getIdx=';
var_export($z->getCommentIndex(0));
echo "\n";
$z->close();
@unlink($path);
