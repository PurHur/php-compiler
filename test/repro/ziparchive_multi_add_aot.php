<?php
// ZipArchive multi-add must keep every entry (#35454) — NestedJIT previously overwrote.
$path = sys_get_temp_dir() . '/phpc_zip_35454_' . getmypid() . '.zip';
@unlink($path);
$z = new ZipArchive();
$z->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$z->addFromString('a.txt', 'AAA');
echo 'num1=', $z->numFiles, "\n";
$z->addFromString('b.txt', 'BBB');
echo 'num2=', $z->numFiles, "\n";
$z->close();
$z2 = new ZipArchive();
$z2->open($path);
echo 'reopen_num=', $z2->numFiles, "\n";
echo 'a=', var_export($z2->getFromName('a.txt'), true), "\n";
echo 'b=', var_export($z2->getFromName('b.txt'), true), "\n";
echo 'n0=', var_export($z2->getNameIndex(0), true), "\n";
echo 'n1=', var_export($z2->getNameIndex(1), true), "\n";
echo 'loc_a=', var_export($z2->locateName('a.txt'), true), "\n";
echo 'loc_b=', var_export($z2->locateName('b.txt'), true), "\n";
echo 'from0=', var_export($z2->getFromIndex(0), true), "\n";
echo 'from1=', var_export($z2->getFromIndex(1), true), "\n";
// same-name replace (php-src zim_ZipArchive_addFromString)
$z2->close();
@unlink($path);
$z3 = new ZipArchive();
$z3->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$z3->addFromString('x.txt', 'one');
$z3->addFromString('x.txt', 'two');
echo 'repl_num=', $z3->numFiles, "\n";
$z3->close();
$z4 = new ZipArchive();
$z4->open($path);
echo 'repl=', var_export($z4->getFromName('x.txt'), true), "\n";
$z4->close();
@unlink($path);
