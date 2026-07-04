--TEST--
Language: (int) cast on backed int enum case — scalar int not case object (#15982, Zend/zend_enums.c)
--FILE--
<?php
enum E: int { case A = 1; case B = 2; }
echo @(int) E::A, "\n";
echo @(int) E::B, "\n";
?>
--EXPECT--
1
1
