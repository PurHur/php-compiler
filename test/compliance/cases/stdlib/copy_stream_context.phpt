--TEST--
stdlib copy() optional stream context third argument (#13248)
--FILE--
<?php
$dir = sys_get_temp_dir() . '/phpc_copy_ctx_' . getmypid();
@mkdir($dir);
$from = $dir . '/a.txt';
$to = $dir . '/b.txt';
file_put_contents($from, 'x');
$ctx = stream_context_create([]);
var_dump(copy($from, $to, $ctx));
@unlink($to);
@unlink($from);
@rmdir($dir);
?>
--EXPECT--
bool(true)
