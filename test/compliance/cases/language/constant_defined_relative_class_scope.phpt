--TEST--
constant()/defined() resolve self::/static::/parent:: in class scope (issue #29455, Zend zend_constants.c)
--FILE--
<?php
class A { public const X = 1; }
class B extends A {
    public static function parentX() { return constant('parent::X'); }
    public static function selfDefined() { return defined('self::X'); }
}
class C {
    public const X = 42;
    public static function selfX() { return constant('self::X'); }
    public static function staticX() { return constant('static::X'); }
}
class D extends C { public const X = 99; }
enum E {
    case A;
    case B;
    public static function fromName(string $n): self { return constant("self::$n"); }
}
echo C::selfX(), "\n";
echo D::staticX(), "\n";
echo B::parentX(), "\n";
var_export(B::selfDefined()); echo "\n";
echo E::fromName('A')->name, "\n";
try {
    constant('self::X');
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
class Solo {
    public static function parentFail() {
        try {
            return constant('parent::X');
        } catch (Throwable $e) {
            return $e->getMessage();
        }
    }
    public static function selfClass() {
        try {
            return constant('self::class');
        } catch (Throwable $e) {
            return $e->getMessage();
        }
    }
}
echo Solo::parentFail(), "\n";
echo Solo::selfClass(), "\n";
--EXPECT--
42
99
1
true
A
Cannot access "self" when no class scope is active
Cannot access "parent" when current class scope has no parent
Undefined constant self::class
