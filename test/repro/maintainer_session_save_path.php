<?php

declare(strict_types=1);

if (!function_exists('session_save_path')) {
    echo "fail: session_save_path missing\n";
    exit(1);
}

if (!function_exists('session_status')) {
    echo "fail: session_status missing\n";
    exit(1);
}

$default = session_save_path();
if (!is_string($default) || '/var/lib/php/sessions' !== $default) {
    echo 'fail: default='.var_export($default, true)."\n";
    exit(1);
}

$dir = sys_get_temp_dir().'/php-sessions-test-'.bin2hex(random_bytes(4));
if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
    echo "fail: mkdir\n";
    exit(1);
}
$prev = session_save_path($dir);
if (!is_string($prev) || '/var/lib/php/sessions' !== $prev) {
    echo 'fail: prev='.var_export($prev, true)."\n";
    exit(1);
}
if (session_save_path() !== $dir) {
    echo 'fail: updated='.var_export(session_save_path(), true)."\n";
    exit(1);
}

$statusBefore = session_status();
if (1 !== $statusBefore) {
    echo 'fail: status_before='.var_export($statusBefore, true)."\n";
    exit(1);
}

if (!session_start()) {
    echo "fail: session_start\n";
    exit(1);
}
if (2 !== session_status()) {
    echo 'fail: status_active='.var_export(session_status(), true)."\n";
    exit(1);
}

$blocked = session_save_path('/tmp/other');
if (false !== $blocked) {
    echo 'fail: active_change='.var_export($blocked, true)."\n";
    exit(1);
}

session_write_close();
if (1 !== session_status()) {
    echo 'fail: status_after='.var_export(session_status(), true)."\n";
    exit(1);
}

echo "ok\n";
