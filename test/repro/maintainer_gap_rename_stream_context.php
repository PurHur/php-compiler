<?php

declare(strict_types=1);

$dir = sys_get_temp_dir().'/phpc_rename_ctx_'.getmypid();
@mkdir($dir);
$from = $dir.'/a.txt';
$to = $dir.'/b.txt';
file_put_contents($from, 'x');
$ctx = stream_context_create([]);
try {
    $ok = rename($from, $to, $ctx);
    echo 'rename_stream_context_ok='.($ok ? '1' : '0')."\n";
    @rename($to, $from);
} catch (Throwable $e) {
    echo 'rename_stream_context_exc='.get_class($e).':'.$e->getMessage()."\n";
}
@unlink($from);
@rmdir($dir);
