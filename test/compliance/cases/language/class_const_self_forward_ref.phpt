--TEST--
Class constant self:: forward reference to sibling constant (#7382)
--FILE--
<?php
class C {
    public const A = self::B + 1;
    public const B = 1;
}
echo C::A, "\n";
--EXPECT--
2
