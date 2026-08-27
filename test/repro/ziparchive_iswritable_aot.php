<?php
// AOT: ZipArchive::isWritable / setReadOnly NestedJIT (#35478 leftover of #35424).
$z = new ZipArchive();
$p = sys_get_temp_dir() . '/ziw_' . getmypid() . '.zip';
@unlink($p);
var_export($z->open($p, ZipArchive::CREATE));
echo "\n";
var_export($z->addFromString('a.txt', 'A'));
echo "\n";
var_export($z->isWritable());
echo "\n";
var_export($z->setReadOnly(true));
echo "\n";
var_export($z->isWritable());
echo "\n";
var_export($z->setReadOnly(false));
echo "\n";
var_export($z->isWritable());
echo "\n";
var_export($z->close());
echo "\n";
@unlink($p);
