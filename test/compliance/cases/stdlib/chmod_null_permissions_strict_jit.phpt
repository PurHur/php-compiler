--TEST--
stdlib chmod null $permissions TypeError under strict_types JIT (#31213, ext/standard/filestat.c)
--FILE--
<?php
declare(strict_types=1);
$p = tempnam(sys_get_temp_dir(), 'ch');
try {
    var_export(chmod($p, null));
    echo "\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
@unlink($p);
?>
--EXPECT--
chmod(): Argument #2 ($permissions) must be of type int, null given
