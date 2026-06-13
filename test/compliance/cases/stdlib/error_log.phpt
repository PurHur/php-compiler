--TEST--
stdlib error_log() type 3 file append returns true (#3380)
--FILE--
<?php
if (!function_exists('error_log')) {
    die('MISSING error_log');
}
$log = sys_get_temp_dir() . '/phpc-error-log-' . getmypid() . '.log';
@unlink($log);
$ok3 = error_log('file line', 3, $log);
echo $ok3 ? "true\n" : "false\n";
echo file_get_contents($log), "\n";
@unlink($log);
echo "done\n";
--EXPECT--
true
file line
done
