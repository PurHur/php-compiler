<?php
// ZipArchive::getExternalAttributesName / Index — AOT must match VM (#35527 leftover of #20363).
$path = sys_get_temp_dir() . '/phpc_zip_gea_' . getmypid() . '.zip';
@unlink($path);
$z = new ZipArchive();
$z->open($path, ZipArchive::CREATE);
$z->addFromString('a.txt', 'A');
$z->addFromString('b.txt', 'B');
$z->setExternalAttributesName('a.txt', ZipArchive::OPSYS_UNIX, 33188);
$z->setExternalAttributesIndex(1, ZipArchive::OPSYS_DOS, 0x20);
$opsys = 0;
$attr = 0;
echo 'name=';
var_export($z->getExternalAttributesName('a.txt', $opsys, $attr));
echo " opsys=$opsys attr=$attr\n";
$opsys = 0;
$attr = 0;
echo 'index=';
var_export($z->getExternalAttributesIndex(1, $opsys, $attr));
echo " opsys=$opsys attr=$attr\n";
$opsys = 9;
$attr = 9;
echo 'miss=';
var_export($z->getExternalAttributesName('missing.txt', $opsys, $attr));
echo " opsys=$opsys attr=$attr\n";
try {
    $z->getExternalAttributesName('', $opsys, $attr);
    echo "empty=ok\n";
} catch (ValueError $e) {
    echo 'empty=ValueError:' . $e->getMessage() . "\n";
}
$z->close();
@unlink($path);
