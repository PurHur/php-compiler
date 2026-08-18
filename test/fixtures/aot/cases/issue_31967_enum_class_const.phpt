--TEST--
AOT: enum case as a class-constant value (#31967)
--FILE--
<?php
enum E: string {
    case X = 'h';
}
class C {
    public const K = E::X;
}
echo C::K->value;
--EXPECT--
h
