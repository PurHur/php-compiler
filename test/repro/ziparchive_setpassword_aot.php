<?php
// ZipArchive::setPassword — AOT must match VM (#35500 leftover of #35496 / #19873).
$path = sys_get_temp_dir() . '/phpc_zip_35500_' . getmypid() . '.zip';
@unlink($path);
$z = new ZipArchive();
$z->open($path, ZipArchive::CREATE);
echo 'empty=';
var_export($z->setPassword(''));
echo "\n";
echo 'set=';
var_export($z->setPassword('secret'));
echo "\n";
$z->addFromString('a.txt', 'A');
echo 'again=';
var_export($z->setPassword('other'));
echo "\n";
$z->close();
@unlink($path);
