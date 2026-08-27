<?php
// AOT: ZipArchive::renameIndex NestedJIT (#35474 leftover of #35450 / #35424).
$path = sys_get_temp_dir() . '/phpc_zip_35474_' . getmypid() . '.zip';
@unlink($path);
$z = new ZipArchive();
$z->open($path, ZipArchive::CREATE);
$z->addFromString('a.txt', 'A');
$z->addFromString('b.txt', 'B');
echo 'ri0=';
var_export($z->renameIndex(0, 'aa.txt'));
echo "\n";
echo 'name0=';
var_export($z->getNameIndex(0));
echo "\n";
echo 'ri1=';
var_export($z->renameIndex(1, 'bb.txt'));
echo "\n";
echo 'name1=';
var_export($z->getNameIndex(1));
echo "\n";
echo 'bad=';
var_export($z->renameIndex(9, 'x.txt'));
echo "\n";
try {
    $z->renameIndex(0, '');
    echo "empty=ok\n";
} catch (ValueError $e) {
    echo 'empty=', $e->getMessage(), "\n";
}
$z->close();
@unlink($path);
