<?php
// ZipArchive::setExternalAttributesName/Index — AOT must match VM (#35515 leftover of #35500 / #20363).
$path = sys_get_temp_dir() . '/phpc_zip_35515_' . getmypid() . '.zip';
@unlink($path);
$z = new ZipArchive();
$z->open($path, ZipArchive::CREATE);
$z->addFromString('a.txt', 'AAAA');
$z->addFromString('b.txt', 'BBBB');
echo 'name=';
var_export($z->setExternalAttributesName('a.txt', ZipArchive::OPSYS_UNIX, 33188));
echo "\n";
echo 'idx=';
var_export($z->setExternalAttributesIndex(1, ZipArchive::OPSYS_DOS, 0x20));
echo "\n";
echo 'miss=';
var_export($z->setExternalAttributesName('nope.txt', ZipArchive::OPSYS_UNIX, 0));
echo "\n";
echo 'badidx=';
var_export($z->setExternalAttributesIndex(99, ZipArchive::OPSYS_UNIX, 0));
echo "\n";
echo 'empty=';
try {
    $z->setExternalAttributesName('', ZipArchive::OPSYS_UNIX, 0);
    echo 'noerr';
} catch (ValueError $e) {
    echo 'ValueError';
}
echo "\n";
$z->close();
@unlink($path);
