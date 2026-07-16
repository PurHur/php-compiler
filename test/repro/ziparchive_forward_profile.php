<?php
echo 'class=', (int) class_exists('ZipArchive', false), "\n";
echo 'ext=', (int) extension_loaded('zip'), "\n";

$path = sys_get_temp_dir() . '/phpc_zip_' . getmypid() . '.zip';
@unlink($path);

$zip = new ZipArchive();
$flags = ZipArchive::CREATE | ZipArchive::OVERWRITE;
$opened = $zip->open($path, $flags);
echo 'open=', var_export($opened, true), "\n";
echo 'add=', var_export($zip->addFromString('hello.txt', 'zip payload'), true), "\n";
echo 'numFiles=', (int) $zip->numFiles, "\n";
echo 'count=', (int) $zip->count(), "\n";
echo 'close=', var_export($zip->close(), true), "\n";

$zip2 = new ZipArchive();
echo 'reopen=', var_export($zip2->open($path), true), "\n";
echo 'get=', var_export($zip2->getFromName('hello.txt'), true), "\n";
echo 'status=', $zip2->getStatusString(), "\n";
$zip2->close();
@unlink($path);
