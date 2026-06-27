<?php
declare(strict_types=1);
$path = '/tmp/example/sub/file.php';
$f = new SplFileInfo($path);
echo $f->getPath(), "\n";
echo $f->getFilename(), "\n";
echo $f->getBasename('.php'), "\n";
echo $f->getPathname(), "\n";
echo (string) $f, "\n";
