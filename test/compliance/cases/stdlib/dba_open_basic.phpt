--TEST--
stdlib dba_open flatfile CRUD round-trip (#4422, ext/dba/dba.c)
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
echo 'open=', function_exists('dba_open') ? '1' : '0', "\n";
echo 'ext=', extension_loaded('dba') ? '1' : '0', "\n";
echo 'conn_class=', class_exists('Dba\\Connection', false) ? '1' : '0', "\n";
$handlers = dba_handlers();
echo 'has_flatfile=', (is_array($handlers) && in_array('flatfile', $handlers, true)) ? '1' : '0', "\n";

$path = sys_get_temp_dir() . '/phpc_dba_4422_' . getmypid() . '.db';
@unlink($path);
$id = dba_open($path, 'c', 'flatfile');
echo 'opened=', ($id instanceof Dba\Connection) ? '1' : '0', "\n";
echo 'insert=', dba_insert('k', 'v', $id) ? '1' : '0', "\n";
echo 'insert_dup=', dba_insert('k', 'other', $id) ? '1' : '0', "\n";
echo 'fetch=', dba_fetch('k', $id), "\n";
echo 'exists=', dba_exists('k', $id) ? '1' : '0', "\n";
echo 'replace=', dba_replace('k', 'v2', $id) ? '1' : '0', "\n";
echo 'fetch2=', dba_fetch('k', $id), "\n";
echo 'delete=', dba_delete('k', $id) ? '1' : '0', "\n";
echo 'exists_after=', dba_exists('k', $id) ? '1' : '0', "\n";
dba_close($id);
@unlink($path);
echo "ok\n";
?>
--EXPECT--
open=1
ext=1
conn_class=1
has_flatfile=1
opened=1
insert=1
insert_dup=0
fetch=v
exists=1
replace=1
fetch2=v2
delete=1
exists_after=0
ok
