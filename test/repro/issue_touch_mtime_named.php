<?php
$f = sys_get_temp_dir() . '/phpc_touch_' . getmypid();
touch($f, mtime: time(), atime: time() - 100);
var_export(fileatime($f) < time());
@unlink($f);
