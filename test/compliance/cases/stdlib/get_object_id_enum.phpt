--TEST--
stdlib get_object_id() on enum cases — stable singleton handle (#5837, ext/standard/basic_functions.c)
--FILE--
<?php
enum E: int { case A = 1; case B = 2; }
enum U { case X; case Y; }

$id1 = get_object_id(E::A);
$id2 = get_object_id(E::A);
$id3 = get_object_id(E::B);
var_export($id1 === $id2);
echo "\n";
var_export($id1 !== $id3);
echo "\n";

$u1 = get_object_id(U::X);
$u2 = get_object_id(U::X);
$u3 = get_object_id(U::Y);
var_export($u1 === $u2);
echo "\n";
var_export($u1 !== $u3);
echo "\n";

function f(mixed $x): int {
    return get_object_id($x);
}
echo ($id1 === f(E::A)) ? "arg_same\n" : "arg_changed\n";
--EXPECT--
true
true
true
true
arg_same
