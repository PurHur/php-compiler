--TEST--
stdlib scandir(null $sorting_order) soft DEP+coerce outside strict_types (#31244, ext/standard/dir.c)
--FILE--
<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
$dir = sys_get_temp_dir() . '/phpc_scandir_null_sort_' . getmypid();
@mkdir($dir);
@file_put_contents($dir . '/a.txt', 'x');
try {
    $r = scandir($dir, null);
    echo is_array($r) ? 'array' : var_export($r, true), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
@unlink($dir . '/a.txt');
@rmdir($dir);
?>
--EXPECTF--
%ADeprecated: scandir(): Passing null to parameter #2 ($sorting_order) of type int is deprecated in %s on line %d
array
