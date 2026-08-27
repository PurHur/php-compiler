<?php
// AOT: ZipArchive unchangeIndex/unchangeName restore open-time names (#35491 leftover of #35486).
$path = sys_get_temp_dir() . '/phpc_zip_35491_' . getmypid() . '.zip';
@unlink($path);
$z = new ZipArchive();
$z->open($path, ZipArchive::CREATE);
$z->addFromString('a.txt', 'A');
$z->close();

$z = new ZipArchive();
$z->open($path);
$z->renameIndex(0, 'b.txt');
echo 'before=';
var_export($z->getNameIndex(0));
echo "\n";
echo 'ui=';
var_export($z->unchangeIndex(0));
echo "\n";
echo 'after=';
var_export($z->getNameIndex(0));
echo "\n";

$z->renameName('a.txt', 'c.txt');
echo 'rn=';
var_export($z->getNameIndex(0));
echo "\n";
echo 'un=';
var_export($z->unchangeName('c.txt'));
echo "\n";
echo 'final=';
var_export($z->getNameIndex(0));
echo "\n";
$z->close();
@unlink($path);
