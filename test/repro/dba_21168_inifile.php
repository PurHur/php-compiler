<?php
$path = sys_get_temp_dir() . '/phpc_dba_21168_repro_' . getmypid() . '.ini';
@unlink($path);
$handlers = dba_handlers();
var_dump(in_array('inifile', $handlers, true));
var_export(dba_key_split('[sec]name'));
echo "\n";
$id = dba_open($path, 'c', 'inifile');
dba_insert(['grp', 'k'], 'v', $id);
var_dump(dba_fetch(['grp', 'k'], $id));
var_dump(dba_optimize($id), dba_sync($id));
dba_close($id);
@unlink($path);
