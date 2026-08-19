--TEST--
stdlib hash_update_stream() — JIT file digest into HashContext (#32483)
--JIT--
--FILE--
<?php
$path = sys_get_temp_dir() . '/hus_jit_' . getmypid() . '.txt';
file_put_contents($path, 'hello world');
$h = fopen($path, 'rb');
$ctx = hash_init('sha256');
var_dump(hash_update_stream($ctx, $h));
$expected = hash_file('sha256', $path);
$actual = hash_final($ctx);
echo $expected === $actual ? "match\n" : "mismatch\n";
fclose($h);
unlink($path);
--EXPECT--
int(11)
match
