--TEST--
stdlib mkdir null $permissions TypeError under strict_types (#31211, ext/standard/file.c)
--FILE--
<?php
declare(strict_types=1);
$dir = sys_get_temp_dir() . '/phpc_mkdir_null_perms_' . getmypid();
@rmdir($dir);
try {
    var_export(mkdir($dir, null));
    echo "\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
if (is_dir($dir)) {
    rmdir($dir);
}
?>
--EXPECT--
mkdir(): Argument #2 ($permissions) must be of type int, null given
