--TEST--
mbstring null $string operands coerce when non-strict JIT (#18273)
--JIT--
--FILE--
<?php
echo mb_strlen(null), "\n";
?>
--EXPECT--
0
