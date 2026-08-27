<?php
// ZipArchive::setExternalAttributesName/Index — AOT must match VM (#35516 leftover of #35508 / #20363).
$z = new ZipArchive();
$p = sys_get_temp_dir() . '/phpc_zip_xattr_' . getmypid() . '.zip';
@unlink($p);
$z->open($p, ZipArchive::CREATE);
$z->addFromString('a.txt', 'hi');
$z->addFromString('b.txt', 'yo');
echo 'name=' . var_export($z->setExternalAttributesName('a.txt', ZipArchive::OPSYS_UNIX, 33188), true) . "\n";
echo 'index=' . var_export($z->setExternalAttributesIndex(1, ZipArchive::OPSYS_DOS, 32), true) . "\n";
echo 'miss=' . var_export($z->setExternalAttributesName('missing.txt', ZipArchive::OPSYS_UNIX, 1), true) . "\n";
echo 'badidx=' . var_export($z->setExternalAttributesIndex(9, ZipArchive::OPSYS_UNIX, 1), true) . "\n";
try {
    $z->setExternalAttributesName('', ZipArchive::OPSYS_UNIX, 1);
    echo "empty=ok\n";
} catch (Throwable $e) {
    echo 'empty=' . get_class($e) . ':' . $e->getMessage() . "\n";
}
$z->close();
@unlink($p);
