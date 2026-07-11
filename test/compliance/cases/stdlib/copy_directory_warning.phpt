--TEST--
stdlib copy() — directory source E_WARNING (#11703, ext/standard/file.c)
--FILE--
<?php
error_reporting(E_ALL);
$count = 0;
set_error_handler(static function () use (&$count): bool {
    ++$count;
    return true;
});
$dir = sys_get_temp_dir() . '/phpc_copy_dir_warn_' . getmypid() . '_' . time();
if (!is_dir($dir)) {
    mkdir($dir);
}
$ok = copy($dir, $dir . '_dest');
@rmdir($dir);
echo 'count=' . $count . "\n";
echo $ok ? "true\n" : "false\n";
--EXPECT--
count=1
false
