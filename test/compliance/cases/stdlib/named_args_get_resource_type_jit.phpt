--TEST--
get_resource_type named resource argument (JIT, issue #23342)
--FILE--
<?php
$path = sys_get_temp_dir() . '/phpc_named_grt_jit_' . getmypid() . '.txt';
file_put_contents($path, "x\n");
$f = fopen($path, 'r');
echo get_resource_type(resource: $f), PHP_EOL;
fclose($f);
@unlink($path);
--EXPECT--
stream
