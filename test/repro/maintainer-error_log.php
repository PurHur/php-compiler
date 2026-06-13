<?php
if (!function_exists('error_log')) {
    fwrite(STDERR, "MISSING: error_log\n");
    exit(1);
}
$log = sys_get_temp_dir() . '/phpc-maintainer-error-log-' . getmypid() . '.log';
@unlink($log);
error_log('hello from error_log');
$ok = error_log('file msg', 3, $log);
echo $ok ? "ok\n" : "fail\n";
echo file_get_contents($log);
@unlink($log);
