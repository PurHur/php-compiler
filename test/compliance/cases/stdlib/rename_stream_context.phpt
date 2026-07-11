--TEST--
stdlib rename() optional stream context third argument (#13249)
--FILE--
<?php
$dir = sys_get_temp_dir() . '/phpc_rename_ctx_' . getmypid();
@mkdir($dir);
$from = $dir . '/a.txt';
$to = $dir . '/b.txt';
file_put_contents($from, 'x');
$ctx = stream_context_create([]);
var_dump(rename($from, $to, $ctx));
@rename($to, $from);
@unlink($from);
@rmdir($dir);
?>
--EXPECT--
bool(true)
