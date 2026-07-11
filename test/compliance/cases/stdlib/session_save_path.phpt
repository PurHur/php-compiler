--TEST--
stdlib session_save_path() — get/set save directory (php-src ext/session/session.c; #3418)
--FILE--
<?php
$default = session_save_path();
$dir = sys_get_temp_dir() . '/phpc-session-save-path-test';
$prev = session_save_path($dir);
session_start();
$blocked = session_save_path('/tmp/phpc-session-blocked');
session_write_close();
echo function_exists('session_save_path') ? "exists\n" : "missing\n";
echo $default === '/var/lib/php/sessions' ? "default\n" : "default-bad\n";
echo is_string($prev) && $prev === '/var/lib/php/sessions' ? "prev\n" : "prev-bad\n";
echo session_save_path() === $dir ? "set\n" : "set-bad\n";
echo session_status() === 1 ? "closed\n" : "closed-bad\n";
echo $blocked === false ? "blocked\n" : "blocked-bad\n";
--EXPECT--
exists
default
prev
set
closed
blocked
