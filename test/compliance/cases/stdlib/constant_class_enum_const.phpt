--TEST--
stdlib constant() — class const holding enum case returns enum singleton (#5926, basic_functions.c)
--FILE--
<?php
enum E: string { case A = 'a'; }
class C { public const X = E::A; }
var_dump(constant(C::class . '::X'));
echo (constant('C::X') === E::A) ? "same\n" : "diff\n";
echo get_debug_type(constant('C::X')), "\n";
--EXPECT--
enum(E::A)
same
E
