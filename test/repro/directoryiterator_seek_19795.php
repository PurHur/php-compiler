<?php
$dir = sys_get_temp_dir() . "/phpc_di_seek_19795_" . getmypid();
@mkdir($dir);
$p = $dir . "/a.txt";
file_put_contents($p, "a");
echo method_exists(DirectoryIterator::class, "seek") ? "seek_yes\n" : "seek_no\n";
$di = new DirectoryIterator($dir);
$di->seek(0);
echo "di0=", $di->getFilename(), "\n";
$di->seek(1);
echo "di1=", $di->getFilename(), "\n";
try { $di->seek(999); echo "oob_ok\n"; }
catch (OutOfBoundsException $e) { echo "oob=", $e->getMessage(), "\n"; }
$fi = new FilesystemIterator($dir, FilesystemIterator::SKIP_DOTS);
$fi->seek(0);
echo "fs=", $fi->getFilename(), "\n";
@unlink($p); @rmdir($dir);
