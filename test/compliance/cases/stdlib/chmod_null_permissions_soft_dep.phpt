--TEST--
stdlib chmod null $permissions soft DEP+coerce outside strict_types (#31213, ext/standard/filestat.c)
--FILE--
<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
$p = tempnam(sys_get_temp_dir(), 'ch');
try {
    var_export(chmod($p, null));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
@unlink($p);
?>
--EXPECTF--
%ADeprecated: chmod(): Passing null to parameter #2 ($permissions) of type int is deprecated in %s on line %d
true
