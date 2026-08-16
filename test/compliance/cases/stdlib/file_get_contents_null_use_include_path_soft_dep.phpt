--TEST--
stdlib file_get_contents(null $use_include_path) soft DEP+coerce outside strict_types (#31338, ext/standard/file.c)
--FILE--
<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
$path = sys_get_temp_dir().'/phpc_fgc_null_uip_soft_'.getmypid().'.txt';
file_put_contents($path, 'hello');
try {
    $r = file_get_contents($path, null);
    echo is_string($r) && $r === 'hello' ? "ok\n" : "bad\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
@unlink($path);
?>
--EXPECTF--
%ADeprecated: file_get_contents(): Passing null to parameter #2 ($use_include_path) of type bool is deprecated in %s on line %d
ok
