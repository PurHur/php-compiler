--TEST--
Language: isset()/empty() on enum case ->name/->value (#9890, Zend/zend_enum.c)
--FILE--
<?php
declare(strict_types=1);

enum E: int { case A = 1; }

var_export(isset(E::A->name));
echo "\n";
var_export(isset(E::A->value));
echo "\n";
var_export(empty(E::A->name));
echo "\n";

enum U { case B; }

var_export(isset(U::B->name));
echo "\n";
var_export(isset(U::B->value));
echo "\n";
var_export(empty(U::B->name));
echo "\n";
--EXPECT--
true
true
false
true
false
false
