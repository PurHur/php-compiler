--TEST--
stdlib hash_update_file() — JIT file digest into HashContext (#32464)
--JIT--
--FILE--
<?php
$path = sys_get_temp_dir() . '/huf_jit_' . getmypid() . '.txt';
file_put_contents($path, "hello\n");
$ctx = hash_init('sha256');
var_dump(hash_update_file($ctx, $path));
$expected = hash_file('sha256', $path);
$actual = hash_final($ctx);
echo $expected === $actual ? "match\n" : "mismatch\n";
unlink($path);
--EXPECT--
bool(true)
match
