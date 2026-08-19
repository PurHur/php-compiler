<?php
// Repro #32510 — leftover Type.php always-on __compiler_touch dropped.
// touch() AOT must still compile (php-src ext/standard/filestat.c php_touch).
// AOT is_file() after a successful write is a pre-existing stat gap (file_put_contents
// also returns ok while is_file is false); do not use it as this issue's oracle.
$p = sys_get_temp_dir().'/phpc_32510_touch_'.getmypid();
@unlink($p);
echo touch($p) ? "touch_ok\n" : "touch_bad\n";
@unlink($p);
