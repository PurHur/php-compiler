--TEST--
stdlib dba_optimize/sync/key_split + inifile handler (#21168, ext/dba)
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
$handlers = dba_handlers();
echo 'has_ini=', in_array('inifile', $handlers, true) ? '1' : '0', "\n";
$parts = dba_key_split('[sec]name');
echo 'split=', $parts[0], ',', $parts[1], "\n";
$parts2 = dba_key_split('bare');
echo 'bare=', $parts2[0] === '' ? 'empty' : $parts2[0], ',', $parts2[1], "\n";
echo 'split_false=', dba_key_split(false) === false ? '1' : '0', "\n";

$path = sys_get_temp_dir() . '/phpc_dba_21168_' . getmypid() . '.ini';
@unlink($path);
$id = dba_open($path, 'c', 'inifile');
echo 'opened=', ($id instanceof Dba\Connection) ? '1' : '0', "\n";
echo 'ins=', dba_insert(['grp', 'k'], 'v', $id) ? '1' : '0', "\n";
echo 'fetch=', dba_fetch(['grp', 'k'], $id), "\n";
echo 'opt=', dba_optimize($id) ? '1' : '0', "\n";
echo 'sync=', dba_sync($id) ? '1' : '0', "\n";
dba_close($id);
@unlink($path);
echo "ok\n";
?>
--EXPECT--
has_ini=1
split=sec,name
bare=empty,bare
split_false=1
opened=1
ins=1
fetch=v
opt=1
sync=1
ok
