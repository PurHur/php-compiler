<?php
/**
 * Repro for #25670 — outside-scope private/protected static must dispatch __callStatic.
 */
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
