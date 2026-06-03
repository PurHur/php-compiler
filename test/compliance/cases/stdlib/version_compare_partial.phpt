--TEST--
stdlib version_compare shorter operand parity (#4796)
--FILE--
<?php
echo version_compare('8.2.31', '8.2', '>=') ? "ge\n" : "no\n";
echo version_compare('8.2', '8.2.31', '<=') ? "le\n" : "no\n";
echo version_compare('8.2.31', '8.2.0', '>=') ? "ge0\n" : "no\n";
--EXPECT--
ge
le
ge0
