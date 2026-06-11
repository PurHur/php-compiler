<?php
// Compile-only (#6547): stream_copy_to_string() JIT/AOT lowering via __compiler_stream_copy_to_string.
declare(strict_types=1);
$path = sys_get_temp_dir() . '/phpc_sctstr_co_' . (string) getmypid() . '.txt';
file_put_contents($path, 'copy');
$src = fopen($path, 'rb');
$s = stream_copy_to_string($src);
fclose($src);
@unlink($path);
echo strlen($s), "\n";
