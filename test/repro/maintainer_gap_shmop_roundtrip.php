<?php

declare(strict_types=1);

if (!function_exists('ftok')) {
    fwrite(STDERR, "MISSING: ftok (see #6296)\n");
    exit(1);
}
$key = ftok(__FILE__, 't');
$id = @shmop_open($key !== false ? $key : 1, 'c', 0644, 64);
if ($id === false) {
    echo "open-fail\n";
    exit(0);
}
shmop_write($id, 'hi', 0);
echo shmop_read($id, 0, 2), "\n";
shmop_close($id);
@shmop_delete($id);
