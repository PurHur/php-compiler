<?php

declare(strict_types=1);

$dir = sys_get_temp_dir().'/phpc_stream_ctx_'.getmypid();
@mkdir($dir);
try {
    stream_context_create([]);
    echo "ok\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
@rmdir($dir);
