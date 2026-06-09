<?php

declare(strict_types=1);

require __DIR__.'/../../vendor/autoload.php';

use PHPCompiler\ext\zstd\VmZstdNative;

$p = 'hello zstd bootstrap';
$z = VmZstdNative::compress($p);
echo 'compressed len: '.\strlen($z)."\n";
$d = VmZstdNative::decompress($z);
echo 'decompressed: '.$d."\n";
echo 'match: '.($d === $p ? 'yes' : 'no')."\n";
