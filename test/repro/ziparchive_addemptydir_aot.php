<?php
// AOT: ZipArchive::addEmptyDir NestedJIT (#35465 leftover of #35424 / #19880).
$path = sys_get_temp_dir() . '/phpc_zip_35465_' . getmypid() . '.zip';
@unlink($path);
$z = new ZipArchive();
echo 'open=', var_export($z->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE), true), "\n";
echo 'add=', var_export($z->addEmptyDir('d'), true), "\n";
echo 'num=', $z->numFiles, "\n";
echo 'loc=', var_export($z->locateName('d/'), true), "\n";
echo 'get=', var_export($z->getFromName('d/'), true), "\n";
echo 'dup=', var_export($z->addEmptyDir('d'), true), "\n";
echo 'empty=', var_export($z->addEmptyDir(''), true), "\n";
echo 'slash=', var_export($z->addEmptyDir('e'), true), "\n";
echo 'num2=', $z->numFiles, "\n";
$z->close();
$z2 = new ZipArchive();
$z2->open($path);
echo 'reopen_num=', $z2->numFiles, "\n";
echo 'n0=', var_export($z2->getNameIndex(0), true), "\n";
echo 'n1=', var_export($z2->getNameIndex(1), true), "\n";
echo 'from0=', var_export($z2->getFromIndex(0), true), "\n";
$z2->close();
@unlink($path);
