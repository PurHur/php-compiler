<?php
// AOT: ZipArchive::renameIndex — leftover of #35450 / #35472.
$z = new ZipArchive();
$p = sys_get_temp_dir() . '/zri_' . getmypid() . '.zip';
@unlink($p);
var_export($z->open($p, ZipArchive::CREATE | ZipArchive::OVERWRITE));
echo "\n";
var_export($z->addFromString('a.txt', 'AAA'));
echo "\n";
var_export($z->addFromString('c.txt', 'CCC'));
echo "\n";
var_export($z->renameIndex(0, 'b.txt'));
echo "\n";
var_export($z->renameIndex(1, 'd.txt'));
echo "\n";
var_export($z->getNameIndex(0));
echo "\n";
var_export($z->getNameIndex(1));
echo "\n";
var_export($z->getFromName('b.txt'));
echo "\n";
var_export($z->getFromName('d.txt'));
echo "\n";
var_export($z->getFromName('a.txt'));
echo "\n";
var_export($z->close());
echo "\n";
@unlink($p);
