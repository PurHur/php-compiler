--TEST--
Language: class const array values materialize enum case objects (#5901, zend_constants.c)
--FILE--
<?php
enum E: int {
    case X = 1;
    case Y = 2;
}

class C {
    public const AR = [E::X, E::Y];
    public const NESTED = [[E::X], E::Y];
}

echo var_export(C::AR[0] === E::X, true), "\n";
echo var_export(C::AR[1] === E::Y, true), "\n";
echo var_export(C::NESTED[0][0] === E::X, true), "\n";
echo var_export(C::NESTED[1] === E::Y, true), "\n";
echo get_debug_type(C::AR[0]), "\n";
--EXPECT--
true
true
true
true
E
