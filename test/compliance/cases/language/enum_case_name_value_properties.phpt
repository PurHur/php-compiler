--TEST--
Language: enum case ->name / ->value properties (#9008, Zend/zend_enum.c)
--FILE--
<?php
enum E: string { case A = 'x'; }
$e = E::A;
var_dump($e);
echo $e->name, "\n";
echo $e->value, "\n";
enum Pure { case A; case B; }
echo Pure::A->name, '|', Pure::B->name, "\n";
--EXPECT--
enum(E::A)
A
x
A|B
