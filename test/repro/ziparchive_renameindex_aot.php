<?php
// AOT: ZipArchive::renameIndex NestedJIT (#35473 leftover of #35450 / #35424).
$z = new ZipArchive();
$p = sys_get_temp_dir() . '/zri_' . getmypid() . '.zip';
@unlink($p);
var_export($z->open($p, ZipArchive::CREATE));
echo "\n";
var_export($z->addFromString('a.txt', 'A'));
echo "\n";
var_export($z->addFromString('b.txt', 'B'));
echo "\n";
var_export($z->renameIndex(0, 'aa.txt'));
echo "\n";
var_export($z->getNameIndex(0));
echo "\n";
var_export($z->getFromName('aa.txt'));
echo "\n";
var_export($z->renameIndex(1, 'bb.txt'));
echo "\n";
var_export($z->getNameIndex(1));
echo "\n";
var_export($z->getFromName('bb.txt'));
echo "\n";
var_export($z->close());
echo "\n";
@unlink($p);
