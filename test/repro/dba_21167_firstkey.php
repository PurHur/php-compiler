<?php
$path = sys_get_temp_dir() . '/phpc_dba_21167_repro_' . getmypid() . '.db';
@unlink($path);
$id = dba_open($path, 'c', 'flatfile');
dba_insert('a', '1', $id);
dba_insert('b', '2', $id);
dba_delete('b', $id);
dba_insert('c', '3', $id);
$keys = [];
for ($k = dba_firstkey($id); false !== $k; $k = dba_nextkey($id)) {
    $keys[] = $k;
}
var_export($keys);
echo "\n";
var_export(in_array($path, dba_list(), true));
echo "\n";
dba_close($id);
@unlink($path);
