<?php

declare(strict_types=1);

/**
 * Bootstrap AOT: fopen/fread/fclose stream I/O (issue #1117, #1152).
 *
 * Uses a repo-local fixture path (not sys_get_temp_dir()/getmypid()) so VM and AOT agree.
 */

$dir = 'test/bootstrap-aot/stdlib_stream_fixture';
@mkdir($dir);
$path = $dir.'/sample.txt';
file_put_contents($path, 'stream');
$h = fopen($path, 'r');
$data = fread($h, 6);
fclose($h);
@unlink($path);
@rmdir($dir);
echo is_string($data) && $data === 'stream' ? '1' : '0';
