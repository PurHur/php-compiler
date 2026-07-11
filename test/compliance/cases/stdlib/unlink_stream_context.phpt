--TEST--
stdlib unlink() optional stream context second argument (#13250)
--FILE--
<?php
$dir = sys_get_temp_dir() . '/phpc_unlink_ctx_' . getmypid();
@mkdir($dir);
$path = $dir . '/a.txt';
file_put_contents($path, 'x');
$ctx = stream_context_create([]);
var_dump(unlink($path, $ctx));
@rmdir($dir);
?>
--EXPECT--
bool(true)
