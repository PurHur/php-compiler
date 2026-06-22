--TEST--
Language: enum case ->name / ->value as direct call arguments (#9684, Zend/zend_enum.c)
--FILE--
<?php
enum E: int { case A = 1; }
var_dump(E::A->name);
var_dump(E::A->value);

enum S { case A; }
var_dump(S::A->name);
?>
--EXPECT--
string(1) "A"
int(1)
string(1) "A"
