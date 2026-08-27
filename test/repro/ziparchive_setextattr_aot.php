<?php
// ZipArchive::setExternalAttributesName / Index — AOT must match VM (#35515 leftover of #20363).
$path = sys_get_temp_dir() . '/phpc_zip_xattr_' . getmypid() . '.zip';
@unlink($path);
$z = new ZipArchive();
$z->open($path, ZipArchive::CREATE);
$z->addFromString('a.txt', 'A');
$z->addFromString('b.txt', 'B');
echo 'name=';
var_export($z->setExternalAttributesName('a.txt', ZipArchive::OPSYS_UNIX, 33188));
echo "\n";
echo 'index=';
var_export($z->setExternalAttributesIndex(1, ZipArchive::OPSYS_DOS, 0x20));
echo "\n";
echo 'miss=';
var_export($z->setExternalAttributesName('missing.txt', ZipArchive::OPSYS_UNIX, 1));
echo "\n";
try {
    $z->setExternalAttributesName('', ZipArchive::OPSYS_UNIX, 1);
    echo "empty=ok\n";
} catch (ValueError $e) {
    echo 'empty=ValueError:' . $e->getMessage() . "\n";
}
$z->close();
@unlink($path);
