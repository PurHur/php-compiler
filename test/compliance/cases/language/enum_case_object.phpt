--TEST--
Language: backed enum case expressions materialize enum case objects (#9233, Zend/zend_enum.c)
--FILE--
<?php
declare(strict_types=1);
enum E: int { case A = 1; case B = 2; }
echo get_debug_type(E::A), "\n";
var_dump(E::A);
echo E::A->name, "\n";
echo E::A->value, "\n";
echo get_class(E::A), "\n";
--EXPECT--
E
enum(E::A)
A
1
E
