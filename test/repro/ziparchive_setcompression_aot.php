<?php
// ZipArchive::setCompressionName/Index — AOT must match VM (#35506 leftover of #35500 / #20363).
$path = sys_get_temp_dir() . '/phpc_zip_35506_' . getmypid() . '.zip';
@unlink($path);
$z = new ZipArchive();
$z->open($path, ZipArchive::CREATE);
$z->addFromString('a.txt', 'A');
$z->addFromString('b.txt', 'B');
echo 'name_default=';
var_export($z->setCompressionName('a.txt', ZipArchive::CM_DEFAULT));
echo "\n";
echo 'idx_store=';
var_export($z->setCompressionIndex(1, ZipArchive::CM_STORE));
echo "\n";
echo 'bad_method=';
var_export($z->setCompressionName('a.txt', ZipArchive::CM_DEFLATE));
echo "\n";
echo 'miss=';
var_export($z->setCompressionName('nope.txt', ZipArchive::CM_STORE));
echo "\n";
echo 'bad_idx=';
var_export($z->setCompressionIndex(9, ZipArchive::CM_STORE));
echo "\n";
try {
    $z->setCompressionName('', ZipArchive::CM_STORE);
    echo "empty_name=ok\n";
} catch (Throwable $e) {
    echo 'empty_name=', get_class($e), "\n";
}
$z->close();
@unlink($path);
