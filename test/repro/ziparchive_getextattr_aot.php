<?php
// ZipArchive::getExternalAttributesName/Index — AOT must match VM (#35527 leftover of #35522 / #20363).
$path = sys_get_temp_dir() . '/phpc_zip_35527_' . getmypid() . '.zip';
@unlink($path);
$z = new ZipArchive();
$z->open($path, ZipArchive::CREATE);
$z->addFromString('a.txt', 'AAAA');
$z->addFromString('b.txt', 'BBBB');
$z->setExternalAttributesName('a.txt', ZipArchive::OPSYS_UNIX, 33188);
$z->setExternalAttributesIndex(1, ZipArchive::OPSYS_DOS, 0x20);
$opsys = 0;
$attr = 0;
echo 'name=';
var_export($z->getExternalAttributesName('a.txt', $opsys, $attr));
echo " opsys=$opsys attr=$attr\n";
$opsys = -1;
$attr = -1;
echo 'idx=';
var_export($z->getExternalAttributesIndex(1, $opsys, $attr));
echo " opsys=$opsys attr=$attr\n";
$opsys = 7;
$attr = 7;
echo 'miss=';
var_export($z->getExternalAttributesName('nope.txt', $opsys, $attr));
echo " opsys=$opsys attr=$attr\n";
$opsys = 7;
$attr = 7;
echo 'badidx=';
var_export($z->getExternalAttributesIndex(99, $opsys, $attr));
echo " opsys=$opsys attr=$attr\n";
echo 'empty=';
try {
    $z->getExternalAttributesName('', $opsys, $attr);
    echo 'noerr';
} catch (ValueError $e) {
    echo 'ValueError';
}
echo "\n";
$z->close();
@unlink($path);
