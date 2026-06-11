<?php

declare(strict_types=1);

$path = sys_get_temp_dir().'/phpc_fifo_'.getmypid();
if (file_exists($path)) {
    @unlink($path);
}
var_export(posix_mkfifo($path, 0600));
echo "\n";
@unlink($path);
