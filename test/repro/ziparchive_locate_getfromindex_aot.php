<?php
// ZipArchive locateName / getFromIndex after CREATE roundtrip — AOT must match VM (#35437).
$path = sys_get_temp_dir() . '/phpc_zip_35437_' . getmypid() . '.zip';
@unlink($path);
$z = new ZipArchive();
$z->open($path, ZipArchive::CREATE);
$z->addFromString('f.txt', 'hello');
$z->close();
$z2 = new ZipArchive();
$z2->open($path);
var_export($z2->locateName('f.txt'));
echo "\n";
var_export($z2->getFromIndex(0));
echo "\n";
var_export($z2->getFromName('f.txt'));
echo "\n";
$z2->close();
@unlink($path);
