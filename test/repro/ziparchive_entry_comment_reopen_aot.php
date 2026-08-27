<?php
// ZipArchive entry comments must survive close/reopen under AOT (#35493 leftover of #35486).
$path = sys_get_temp_dir() . '/phpc_zip_35493_' . getmypid() . '.zip';
@unlink($path);
$z = new ZipArchive();
$z->open($path, ZipArchive::CREATE);
$z->addFromString('a.txt', 'AAA');
$z->addFromString('b.txt', 'BBB');
$z->setCommentName('a.txt', 'ec');
$z->setCommentIndex(1, 'ei');
$z->close();
$z2 = new ZipArchive();
$z2->open($path);
echo 'reopenName=';
var_export($z2->getCommentName('a.txt'));
echo "\n";
echo 'reopenIdx=';
var_export($z2->getCommentIndex(1));
echo "\n";
$z2->close();
@unlink($path);
