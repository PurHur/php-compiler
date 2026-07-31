--TEST--
Dba\Connection serialize()/unserialize() reject (issue #23113, ext/dba/dba.stub.php)
--ENV--
PHP_COMPILER_ENABLE_DBA=1
--SKIPIF--
<?php
if (!\PHPCompiler\ext\dba\DbaExtensionPolicy::advertisesExtension()) {
    die('skip dba withheld (#24134)');
}
?>
--FILE--
<?php
$path = sys_get_temp_dir() . '/phpc_dba_ser_' . getmypid() . '.db';
@unlink($path);
$d = dba_open($path, 'n', 'flatfile');
try {
    serialize($d);
    echo "serialize:no-throw\n";
} catch (Throwable $e) {
    echo 'serialize:', get_class($e), ':', $e->getMessage(), "\n";
}
try {
    unserialize('O:14:"Dba\\Connection":0:{}');
    echo "unserialize:no-throw\n";
} catch (Throwable $e) {
    echo 'unserialize:', get_class($e), ':', $e->getMessage(), "\n";
}
@unlink($path);
--EXPECT--
serialize:Exception:Serialization of 'Dba\Connection' is not allowed
unserialize:Exception:Unserialization of 'Dba\Connection' is not allowed
