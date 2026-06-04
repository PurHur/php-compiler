--TEST--
Backed enum relational vs scalar is always false (#5812, Zend/zend_operators.c)
--FILE--
<?php
declare(strict_types=1);
enum E: int { case A = 1; }
var_dump(E::A < 2);
var_dump(E::A > 2);
var_dump(E::A <= 2);
var_dump(E::A >= 2);
var_dump(E::A < E::A);
?>
--EXPECT--
bool(false)
bool(false)
bool(false)
bool(false)
bool(false)
