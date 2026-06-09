<?php
// Compile-only (#3272): stream_copy_to_stream() JIT/AOT lowering via __compiler_stream_copy_to_stream.
declare(strict_types=1);
$path = sys_get_temp_dir() . '/phpc_scts_co_' . (string) getmypid() . '.txt';
file_put_contents($path, 'copy');
$src = fopen($path, 'rb');
$dst = fopen('php://memory', 'wb+');
$n = stream_copy_to_stream($src, $dst);
fclose($src);
fclose($dst);
@unlink($path);
echo $n, "\n";
