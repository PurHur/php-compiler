<?php

$dir = 'test/bootstrap-aot/stdlib_stream_fixture';
@mkdir($dir);
$path = $dir.'/sample.txt';
file_put_contents($path, 'x');
$h = fopen($path, 'r');
try {
    fread($h, 0);
    echo "no error\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
fclose($h);
@unlink($path);
@rmdir($dir);
