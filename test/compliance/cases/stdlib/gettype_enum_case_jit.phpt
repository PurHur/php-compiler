--TEST--
JIT: gettype() on enum cases returns object (#5496)
--JIT--
--FILE--
<?php
enum E: string { case A = 'x'; }
enum U { case B; }

echo gettype(E::A), "\n";
echo gettype(U::B), "\n";

function f(mixed $x): string {
    return gettype($x);
}
echo f(E::A), "\n";
echo f(U::B), "\n";

$arr = ['k' => E::A];
echo gettype($arr['k']), "\n";
--EXPECT--
object
object
object
object
object
