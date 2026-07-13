--TEST--
stdlib link()/symlink() null path — strict_types TypeError (#18710, ext/standard/filestat.c)
--FILE--
<?php
declare(strict_types=1);
$path = sys_get_temp_dir() . '/phpc_link_symlink_strict_' . getmypid();
try {
    link(null, $path);
    echo "fail\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
link(): Argument #1 ($target) must be of type string, null given
