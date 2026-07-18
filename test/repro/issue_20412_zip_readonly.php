<?php
// Issue #20412 ZipArchive isWritable / setReadOnly
foreach (['isWritable', 'setReadOnly'] as $m) {
    echo $m, '=', method_exists(ZipArchive::class, $m) ? 'yes' : 'no', "\n";
}
$path = sys_get_temp_dir() . '/phpc_zip_ro_' . getmypid() . '.zip';
@unlink($path);
$zip = new ZipArchive();
$zip->open($path, 9);
echo 'writable=', var_export($zip->isWritable(), true), "\n";
$zip->addFromString('a.txt', 'hello');
$zip->setReadOnly(true);
echo 'writable2=', var_export($zip->isWritable(), true), "\n";
echo 'add=', var_export($zip->addFromString('b.txt', 'x'), true), " status=", $zip->status, "\n";
$zip->setReadOnly(false);
echo 'add2=', var_export($zip->addFromString('b.txt', 'x'), true), "\n";
$zip->close();
@unlink($path);
echo "OK\n";
