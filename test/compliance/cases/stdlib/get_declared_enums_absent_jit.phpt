--TEST--
Stdlib: get_declared_enums() absent — not in php-src (JIT, #11248)
--JIT--
--FILE--
<?php
echo function_exists('get_declared_enums') ? '1' : '0', "\n";
enum DeclaredEnum { case A; }
echo in_array('DeclaredEnum', get_declared_classes(), true) ? '1' : '0', "\n";
--EXPECT--
0
1
