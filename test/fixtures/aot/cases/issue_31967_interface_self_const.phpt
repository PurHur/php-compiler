--TEST--
AOT: interface constant via self:: in a const expression (#31967)
--FILE--
<?php
interface I {
    const X = 20;
}
class C implements I {
    const Y = self::X;
}
echo C::Y;
--EXPECT--
20
