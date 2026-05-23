<?php

declare(strict_types=1);

/**
 * Bootstrap AOT: fopen/fread/fclose stream I/O (issue #1117, self-host stdlib wave).
 */

$path = sys_get_temp_dir().'/phpc_stdlib_stream_'.(string) getmypid().'.txt';
file_put_contents($path, 'stream');
$h = fopen($path, 'r');
$data = fread($h, 6);
fclose($h);
@unlink($path);
echo is_string($data) && $data === 'stream' ? '1' : '0';
