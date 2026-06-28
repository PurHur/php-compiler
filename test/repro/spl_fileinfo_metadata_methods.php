<?php

declare(strict_types=1);

$tmp = tempnam(sys_get_temp_dir(), 'sfi');
if (false === $tmp) {
    echo "fail: tempnam\n";
    exit(0);
}
$path = $tmp.'.txt';
rename($tmp, $path);
file_put_contents($path, 'x');

$f = new SplFileInfo($path);
echo $f->getExtension() === 'txt' ? "ok:getExtension\n" : "missing:getExtension\n";
echo \is_string($f->getRealPath()) && '' !== $f->getRealPath() ? "ok:getRealPath\n" : "missing:getRealPath\n";
echo $f->isFile() ? "ok:isFile\n" : "missing:isFile\n";
echo !$f->isDir() ? "ok:isDir\n" : "missing:isDir\n";
echo $f->isReadable() ? "ok:isReadable\n" : "missing:isReadable\n";
echo $f->isWritable() ? "ok:isWritable\n" : "missing:isWritable\n";
echo 1 === $f->getSize() ? "ok:getSize\n" : "missing:getSize\n";
echo \is_int($f->getMTime()) ? "ok:getMTime\n" : "missing:getMTime\n";
echo \is_int($f->getCTime()) ? "ok:getCTime\n" : "missing:getCTime\n";

@unlink($path);
