<?php

declare(strict_types=1);

/** Bootstrap AOT: file_put_contents, file_get_contents, file_exists, mkdir, unlink, rmdir. */

$path = 'test/bootstrap-aot/stdlib_fs_fixture';
@mkdir($path);
$file = $path.'/sample.txt';
file_put_contents($file, 'bootstrap');
$contents = file_get_contents($file);
$exists = file_exists($file);
@unlink($file);
@rmdir($path);
echo is_string($contents) ? '1' : '0';
echo $exists ? '1' : '0';
