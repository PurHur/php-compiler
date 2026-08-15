--TEST--
stdlib mkdir null $permissions soft DEP+coerce outside strict_types (#31211, ext/standard/file.c)
--FILE--
<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
$dir = sys_get_temp_dir() . '/phpc_mkdir_null_perms_soft_' . getmypid();
@rmdir($dir);
try {
    var_export(mkdir($dir, null));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
if (is_dir($dir)) {
    rmdir($dir);
}
?>
--EXPECTF--
%ADeprecated: mkdir(): Passing null to parameter #2 ($permissions) of type int is deprecated in %s on line %d
true
