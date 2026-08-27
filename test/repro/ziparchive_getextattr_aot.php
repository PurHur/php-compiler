<?php
// ZipArchive::getExternalAttributesName/Index — AOT must match VM (#35529 leftover of #35515 / #20363).
$path = sys_get_temp_dir() . '/phpc_zip_35529_' . getmypid() . '.zip';
@unlink($path);
$z = new ZipArchive();
$z->open($path, ZipArchive::CREATE);
$z->addFromString('a.txt', 'AAAA');
$z->addFromString('b.txt', 'BBBB');
$z->setExternalAttributesName('a.txt', ZipArchive::OPSYS_UNIX, 33188);
$z->setExternalAttributesIndex(1, ZipArchive::OPSYS_DOS, 0x20);
$opsys = $attr = -1;
echo 'name=';
var_export($z->getExternalAttributesName('a.txt', $opsys, $attr));
echo '|'.$opsys.'|'.$attr."\n";
$opsys2 = $attr2 = -1;
echo 'idx=';
var_export($z->getExternalAttributesIndex(1, $opsys2, $attr2));
echo '|'.$opsys2.'|'.$attr2."\n";
$opsys3 = $attr3 = -1;
echo 'miss=';
var_export($z->getExternalAttributesName('nope.txt', $opsys3, $attr3));
echo '|'.$opsys3.'|'.$attr3."\n";
$opsys4 = $attr4 = -1;
echo 'badidx=';
var_export($z->getExternalAttributesIndex(99, $opsys4, $attr4));
echo '|'.$opsys4.'|'.$attr4."\n";
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
