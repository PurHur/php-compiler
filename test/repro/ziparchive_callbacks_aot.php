<?php
// ZipArchive::registerProgressCallback/registerCancelCallback — AOT must match VM (#35539).
$path = sys_get_temp_dir() . '/phpc_zip_35539_' . getmypid() . '.zip';
@unlink($path);
$z = new ZipArchive();
$z->open($path, ZipArchive::CREATE);
echo 'prog=';
var_export($z->registerProgressCallback(0.5, function ($r) {}));
echo "\n";
echo 'cancel=';
var_export($z->registerCancelCallback(function () { return 0; }));
echo "\n";
$z->addFromString('a.txt', 'A');
echo 'close=';
var_export($z->close());
echo "\n";
@unlink($path);
