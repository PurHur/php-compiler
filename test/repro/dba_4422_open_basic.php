<?php
$path = sys_get_temp_dir() . '/phpc_dba_repro_' . getmypid() . '.db';
@unlink($path);
var_dump(function_exists('dba_open'));
var_dump(extension_loaded('dba'));
$id = dba_open($path, 'c', 'flatfile');
var_dump($id instanceof Dba\Connection);
dba_insert('k', 'v', $id);
var_dump(dba_fetch('k', $id));
dba_close($id);
@unlink($path);
