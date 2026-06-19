--TEST--
Language: interface static property hooks — implementing class (#9754, zend_property_hooks.c)
--FILE--
<?php
interface I {
    public static string $p { get; set; }
}
class C implements I {
    private static string $_p = 'x';
    public static string $p {
        get => self::$_p;
        set { self::$_p = $value; }
    }
}
echo C::$p, "\n";
C::$p = 'y';
echo C::$p, "\n";
--EXPECT--
x
y
