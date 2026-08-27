<?php
// ZipArchive CREATE roundtrip — AOT must match VM (#35424).
$path = sys_get_temp_dir() . '/phpc_zip_35424_' . getmypid() . '.zip';
@unlink($path);
$z = new ZipArchive();
$rc = $z->open($path, ZipArchive::CREATE);
echo 'open=';
var_export($rc);
echo "\n";
$z->addFromString('a.txt', 'hello');
$z->close();
$z2 = new ZipArchive();
$z2->open($path);
echo $z2->getFromName('a.txt'), "\n";
$z2->close();
@unlink($path);
