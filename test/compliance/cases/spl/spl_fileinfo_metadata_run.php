<?php
declare(strict_types=1);
$tmp = tempnam(sys_get_temp_dir(), 'sfi');
if (false === $tmp) {
    exit(1);
}
$path = $tmp.'.txt';
rename($tmp, $path);
file_put_contents($path, 'x');
$f = new SplFileInfo($path);
echo $f->getExtension() === 'txt' ? "ext-ok\n" : "ext-bad\n";
echo \is_string($f->getRealPath()) && '' !== $f->getRealPath() ? "realpath-ok\n" : "realpath-bad\n";
echo $f->isFile() ? "isfile-ok\n" : "isfile-bad\n";
echo !$f->isDir() ? "isdir-ok\n" : "isdir-bad\n";
echo $f->isReadable() ? "readable-ok\n" : "readable-bad\n";
echo $f->isWritable() ? "writable-ok\n" : "writable-bad\n";
echo 1 === $f->getSize() ? "size-ok\n" : "size-bad\n";
@unlink($path);
