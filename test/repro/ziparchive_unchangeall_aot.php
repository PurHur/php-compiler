<?php
// ZipArchive::unchangeAll — AOT must match VM (#35490 leftover of #35486 / #20387).
$path = sys_get_temp_dir() . '/phpc_zip_35490_' . getmypid() . '.zip';
@unlink($path);
$z = new ZipArchive();
$z->open($path, ZipArchive::CREATE);
$z->addFromString('a.txt', 'A');
$z->addFromString('b.txt', 'B');
echo 'before_num=' . $z->numFiles . "\n";
echo 'unchangeAll=';
var_export($z->unchangeAll());
echo "\n";
echo 'after_num=' . $z->numFiles . "\n";
echo 'get_a=';
var_export($z->getFromName('a.txt'));
echo "\n";
// Reopen path: snapshot keeps on-disk entries after mutate+unchangeAll.
$z->addFromString('c.txt', 'C');
$z->close();
$z2 = new ZipArchive();
$z2->open($path);
$z2->addFromString('d.txt', 'D');
echo 'reopen_before=' . $z2->numFiles . "\n";
echo 'reopen_unchange=';
var_export($z2->unchangeAll());
echo "\n";
echo 'reopen_after=' . $z2->numFiles . "\n";
echo 'reopen_c=';
var_export($z2->getFromName('c.txt'));
echo "\n";
echo 'reopen_d=';
var_export($z2->getFromName('d.txt'));
echo "\n";
$z2->close();
@unlink($path);
