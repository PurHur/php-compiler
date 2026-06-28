<?php

declare(strict_types=1);

$dir = sys_get_temp_dir().'/phpc_unlink_ctx_'.getmypid();
@mkdir($dir);
$path = $dir.'/a.txt';
file_put_contents($path, 'x');
$ctx = stream_context_create([]);
try {
    $ok = unlink($path, $ctx);
    echo 'unlink_stream_context_ok='.($ok ? '1' : '0')."\n";
} catch (Throwable $e) {
    echo 'unlink_stream_context_exc='.get_class($e).':'.$e->getMessage()."\n";
}
@rmdir($dir);
