<?php
// AOT: ZipArchive::deleteIndex (# leftover of #35450).
$z = new ZipArchive();
$p = sys_get_temp_dir() . '/zdi_' . getmypid() . '.zip';
@unlink($p);
var_export($z->open($p, ZipArchive::CREATE));
echo "\n";
var_export($z->addFromString('a.txt', 'hi'));
echo "\n";
var_export($z->deleteIndex(0));
echo "\n";
var_export($z->getFromName('a.txt'));
echo "\n";
var_export($z->close());
echo "\n";
@unlink($p);
