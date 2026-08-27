<?php
// AOT: ZipArchive rename* empty new_name must raise ValueError (#35481 leftover of #35472).
$path = sys_get_temp_dir() . '/phpc_zip_35481_' . getmypid() . '.zip';
@unlink($path);
$z = new ZipArchive();
$z->open($path, ZipArchive::CREATE);
$z->addFromString('a.txt', 'A');
try {
    $z->renameIndex(0, '');
    echo "ri=ok\n";
} catch (ValueError $e) {
    echo 'ri=', $e->getMessage(), "\n";
}
try {
    $z->renameName('a.txt', '');
    echo "rn=ok\n";
} catch (ValueError $e) {
    echo 'rn=', $e->getMessage(), "\n";
}
echo 'keep=';
var_export($z->getNameIndex(0));
echo "\n";
$z->close();
@unlink($path);
