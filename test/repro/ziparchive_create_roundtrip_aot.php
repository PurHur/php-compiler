<?php

declare(strict_types=1);

/**
 * AOT: ZipArchive CREATE roundtrip open/addFromString/close/getFromName (#35424).
 *
 * php-src: ext/zip/php_zip.c — zim_ZipArchive_open / addFromString / close / getFromName
 */
$path = sys_get_temp_dir().'/phpc_zip_35424_'.getmypid().'.zip';
@unlink($path);
$z = new ZipArchive();
$rc = $z->open($path, ZipArchive::CREATE);
var_export($rc);
echo "\n";
$ok = $z->addFromString('a.txt', 'hello');
var_export($ok);
echo "\n";
$ok2 = $z->close();
var_export($ok2);
echo "\n";
$z2 = new ZipArchive();
$z2->open($path);
$got = $z2->getFromName('a.txt');
var_export($got);
echo "\n";
@unlink($path);
