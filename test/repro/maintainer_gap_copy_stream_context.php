<?php

declare(strict_types=1);

$dir = sys_get_temp_dir().'/phpc_copy_ctx_'.getmypid();
@mkdir($dir);
$from = $dir.'/a.txt';
$to = $dir.'/b.txt';
file_put_contents($from, 'x');
$ctx = stream_context_create([]);
try {
    $ok = copy($from, $to, $ctx);
    echo 'copy_stream_context_ok='.($ok ? '1' : '0')."\n";
} catch (Throwable $e) {
    echo 'copy_stream_context_exc='.get_class($e).':'.$e->getMessage()."\n";
}
@unlink($to);
@unlink($from);
@rmdir($dir);
