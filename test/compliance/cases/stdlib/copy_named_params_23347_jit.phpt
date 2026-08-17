--TEST--
JIT: copy() named from:/to: arguments (#23347)
--FILE--
<?php
$a = sys_get_temp_dir() . '/phpc_copy_named_23347_jit_a_' . getmypid();
$b = sys_get_temp_dir() . '/phpc_copy_named_23347_jit_b_' . getmypid();
file_put_contents($a, 'x');
@unlink($b);
var_export(copy(from: $a, to: $b));
echo "\n";
echo is_file($b) ? file_get_contents($b) : 'missing', "\n";
@unlink($a);
@unlink($b);
--EXPECT--
true
x
