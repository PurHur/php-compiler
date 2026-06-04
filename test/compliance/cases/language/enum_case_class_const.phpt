--TEST--
Language: enum case ::class pseudo-constant returns enum FQCN (zend_compile.c, #5662)
--FILE--
<?php
enum E: int { case A = 1; }
echo E::A::class, "\n";
enum U { case B; }
echo U::B::class, "\n";
--EXPECT--
E
U
