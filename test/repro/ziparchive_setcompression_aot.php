<?php
// ZipArchive::setCompressionName/Index — AOT must match VM (#35507 leftover of #35500 / #20363).
$path = sys_get_temp_dir() . '/phpc_zip_35507_' . getmypid() . '.zip';
@unlink($path);
$z = new ZipArchive();
$z->open($path, ZipArchive::CREATE);
$z->addFromString('a.txt', 'AAAA');
echo 'store=';
var_export($z->setCompressionName('a.txt', ZipArchive::CM_STORE));
echo "\n";
echo 'deflate=';
var_export($z->setCompressionIndex(0, ZipArchive::CM_DEFLATE));
echo "\n";
echo 'default=';
var_export($z->setCompressionName('a.txt', ZipArchive::CM_DEFAULT));
echo "\n";
echo 'miss=';
var_export($z->setCompressionName('nope.txt', ZipArchive::CM_STORE));
echo "\n";
echo 'badidx=';
var_export($z->setCompressionIndex(99, ZipArchive::CM_STORE));
echo "\n";
echo 'empty=';
try {
    $z->setCompressionName('', ZipArchive::CM_STORE);
    echo 'noerr';
} catch (ValueError $e) {
    echo 'ValueError';
}
echo "\n";
$z->close();
@unlink($path);
