<?php

declare(strict_types=1);

// Compile-only (#9286): fread() length <= 0 ValueError lowering for AOT lint.

$dir = 'test/bootstrap-aot/stdlib_stream_fixture';
@mkdir($dir);
$path = $dir.'/sample.txt';
file_put_contents($path, 'x');
$h = fopen($path, 'r');
try {
    fread($h, 0);
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
fclose($h);
@unlink($path);
@rmdir($dir);
