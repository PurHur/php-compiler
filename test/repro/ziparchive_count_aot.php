<?php
// AOT: ZipArchive::count Countable (#35466 leftover of #35424).
$z = new ZipArchive();
$p = sys_get_temp_dir() . '/zcnt_' . getmypid() . '.zip';
@unlink($p);
var_export($z->open($p, ZipArchive::CREATE | ZipArchive::OVERWRITE));
echo "\n";
var_export($z->count());
echo "\n";
var_export($z->numFiles);
echo "\n";
var_export($z->addFromString('a.txt', 'x'));
echo "\n";
var_export($z->count());
echo "\n";
var_export($z->numFiles);
echo "\n";
var_export($z->addFromString('b.txt', 'y'));
echo "\n";
var_export($z->count());
echo "\n";
var_export($z->numFiles);
echo "\n";
var_export($z->close());
echo "\n";
@unlink($p);
