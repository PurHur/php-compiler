--TEST--
language: inaccessible private/protected static → __callStatic (issue #25670, re-#3273)
--FILE--
<?php
class A {
    private static function hid() { return "p"; }
    protected static function prot() { return "pr"; }
    public static function __callStatic($n, $a) {
        echo "CS_$n\n";
        return "m";
    }
    public static function inside() {
        return self::hid();
    }
}
class B extends A {
    public static function fromChild() {
        return parent::prot();
    }
    public static function childPriv() {
        return parent::hid();
    }
}
echo A::hid(), "\n";
echo A::prot(), "\n";
echo A::inside(), "\n";
echo B::fromChild(), "\n";
echo B::childPriv(), "\n";
--EXPECT--
CS_hid
m
CS_prot
m
p
pr
CS_hid
m
