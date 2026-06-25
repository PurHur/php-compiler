<?php
// Issue #11703 — copy($directory, $dest) must emit E_WARNING before false.
error_reporting(E_ALL);
ini_set('display_errors', '1');
$copy_warn_count = 0;
set_error_handler(static function () use (&$copy_warn_count): bool {
    ++$copy_warn_count;

    return true;
});
$dir = sys_get_temp_dir() . '/phpc_gap_copy_dir_' . getmypid() . '_' . time();
if (!is_dir($dir)) {
    mkdir($dir);
}
$ok = copy($dir, $dir . '_dest');
@rmdir($dir);
echo 'count=' . $copy_warn_count . "\n";
echo $ok ? "true\n" : "false\n";
