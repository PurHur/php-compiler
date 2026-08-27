<?php
// AOT: ZipArchive::extractTo returns null (ExternalMethod stub) — VM matches Zend.
$z = new ZipArchive();
$path = sys_get_temp_dir() . '/phpc_zip_extract_' . getmypid() . '.zip';
@unlink($path);
$z->open($path, ZipArchive::CREATE);
$z->addFromString('a.txt', 'hello');
$z->close();
$z2 = new ZipArchive();
$z2->open($path);
$dir = sys_get_temp_dir() . '/phpc_zip_out_' . getmypid();
@mkdir($dir);
$r = $z2->extractTo($dir);
echo 'extractTo=' . var_export($r, true) . "\n";
$got = @file_get_contents($dir . '/a.txt');
echo 'content=' . var_export($got, true) . "\n";
$z2->close();
@unlink($path);
@unlink($dir . '/a.txt');
@rmdir($dir);
