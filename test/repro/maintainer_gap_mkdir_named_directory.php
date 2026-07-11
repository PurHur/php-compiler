<?php

declare(strict_types=1);

$dir = sys_get_temp_dir().'/phpc_mkdir_named_'.getmypid();
@rmdir($dir);
$ok = mkdir(directory: $dir, permissions: 0700, recursive: false);
if ($ok && is_dir($dir)) {
    @rmdir($dir);
    echo "mkdir_named_directory_ok=1\n";
    exit(0);
}
echo "mkdir_named_directory_ok=0\n";
exit(1);
