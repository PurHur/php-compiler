--TEST--
stdlib dba_firstkey/nextkey/list after flatfile CRUD (#21167, ext/dba/dba.c)
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
$path = sys_get_temp_dir() . '/phpc_dba_21167_' . getmypid() . '.db';
@unlink($path);
$id = dba_open($path, 'c', 'flatfile');
dba_insert('a', '1', $id);
dba_insert('b', '2', $id);
dba_insert('c', '3', $id);
dba_delete('b', $id);
$keys = [];
for ($k = dba_firstkey($id); $k !== false; $k = dba_nextkey($id)) {
    $keys[] = $k;
}
echo 'keys=', implode(',', $keys), "\n";
$list = dba_list();
echo 'list_has=', (is_array($list) && in_array($path, $list, true)) ? '1' : '0', "\n";
dba_close($id);
@unlink($path);
echo "ok\n";
?>
--EXPECT--
keys=a,c
list_has=1
ok
