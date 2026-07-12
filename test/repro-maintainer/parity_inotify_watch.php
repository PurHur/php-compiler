<?php

declare(strict_types=1);

if (!function_exists('inotify_init')) {
    echo "missing\n";
    exit(0);
}

$fd = inotify_init();
if (false === $fd) {
    echo "init_fail\n";
    exit(1);
}

$path = sys_get_temp_dir().'/inotify-test-'.getmypid();
file_put_contents($path, 'a');
$wd = inotify_add_watch($fd, $path, IN_MODIFY);
if (false === $wd) {
    echo "watch_fail\n";
    @unlink($path);
    exit(1);
}

file_put_contents($path, 'b');
$events = inotify_read($fd);
$ok = is_array($events) && count($events) >= 1;
inotify_rm_watch($fd, $wd);
@unlink($path);
echo $ok ? "ok\n" : "fail\n";
