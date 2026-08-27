<?php
// #35437 — AOT ZipArchive::locateName / getFromIndex after CREATE roundtrip
// php-src: ext/zip/php_zip.c zim_ZipArchive_locateName / zim_ZipArchive_getFromIndex
$p = sys_get_temp_dir().'/phpc_zip_locate_'.getmypid().'.zip';
@unlink($p);
$z = new ZipArchive();
echo 'open=', var_export($z->open($p, ZipArchive::CREATE), true), "\n";
echo 'add=', var_export($z->addFromString('f.txt', 'hello'), true), "\n";
echo 'close=', var_export($z->close(), true), "\n";
$z2 = new ZipArchive();
$z2->open($p);
echo 'locate=', var_export($z2->locateName('f.txt'), true), "\n";
echo 'idx=', var_export($z2->getFromIndex(0), true), "\n";
echo 'name=', var_export($z2->getFromName('f.txt'), true), "\n";
echo 'miss=', var_export($z2->locateName('nope'), true), "\n";
@unlink($p);
