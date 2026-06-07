--TEST--
is_object() treats enum case operands as objects (issue #5448, #7199; zend_enum.c)
--FILE--
<?php
enum E: int { case A = 1; }
var_dump(is_object(E::A));
enum U { case X; }
var_dump(is_object(U::X));
var_dump(is_object(1));
--EXPECT--
bool(true)
bool(true)
bool(false)
