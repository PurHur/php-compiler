--TEST--
stdlib pack() nested call / assign-expr operand — binary string preserved (#11365, ext/standard/pack.c)
--FILE--
<?php
$p = pack('C', 0);
echo 'assign_len=', strlen($p), "\n";

echo 'nested_len=', strlen(pack('C', 0)), "\n";

echo 'expr_len=', strlen(($q = pack('C', 0))), "\n";

echo 'bin2hex_nested=', bin2hex(pack('C', 255)), "\n";
--EXPECT--
assign_len=1
nested_len=1
expr_len=1
bin2hex_nested=ff
