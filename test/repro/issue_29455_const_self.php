<?php
class A { public const X = 1; }
class B extends A {
  public static function parentX() { return constant("parent::X"); }
  public static function selfDefined() { return defined("self::X"); }
}
class C {
  public const X = 42;
  public static function selfX() { return constant("self::X"); }
  public static function staticX() { return constant("static::X"); }
}
class D extends C { public const X = 99; }
enum E { case A; case B;
  public static function fromName(string $n): self { return constant("self::$n"); }
}
echo C::selfX(), "\n";
echo D::staticX(), "\n";
echo B::parentX(), "\n";
var_export(B::selfDefined()); echo "\n";
echo E::fromName("A")->name, "\n";
