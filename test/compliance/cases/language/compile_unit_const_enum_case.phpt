--TEST--
Language: compile-unit const with enum case preserves enum object (#9712, #9925, zend_constants.c)
--FILE--
<?php
declare(strict_types=1);

enum E: string { case A = 'x'; }

const G = E::A;

echo get_debug_type(G), "\n";
var_export(G === E::A);
echo "\n";
--EXPECT--
E
true
