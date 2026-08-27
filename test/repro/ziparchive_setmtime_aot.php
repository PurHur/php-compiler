<?php
// ZipArchive::setMtimeName / setMtimeIndex — AOT must match VM (#20363 leftover).
$path = sys_get_temp_dir() . '/phpc_zip_mtime_' . getmypid() . '.zip';
@unlink($path);
$z = new ZipArchive();
$z->open($path, ZipArchive::CREATE);
$z->addFromString('a.txt', 'A');
$z->addFromString('b.txt', 'B');
echo 'name=';
var_export($z->setMtimeName('a.txt', 1700000000));
echo "\n";
echo 'index=';
var_export($z->setMtimeIndex(1, 1700000001));
echo "\n";
echo 'miss=';
var_export($z->setMtimeName('missing.txt', 1));
echo "\n";
try {
    $z->setMtimeName('', 1);
    echo "empty=ok\n";
} catch (ValueError $e) {
    echo 'empty=ValueError:' . $e->getMessage() . "\n";
}
$z->close();
@unlink($path);
