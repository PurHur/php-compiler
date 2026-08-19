<?php
// Repro #32510 — leftover Type.php always-on __compiler_touch dropped.
// touch() AOT must still compile (php-src ext/standard/filestat.c php_touch).
$p = sys_get_temp_dir().'/phpc_32510_touch_'.getmypid();
@unlink($p);
echo touch($p) ? "touch_ok\n" : "touch_bad\n";
echo is_file($p) ? "file_ok\n" : "file_bad\n";
@unlink($p);
