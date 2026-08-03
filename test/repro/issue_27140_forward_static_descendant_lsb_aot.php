<?php
/**
 * #27140 AOT path — string Class::method (array callable is pre-existing AOT gap, #20251).
 */
class A {
  public static function who() { return static::class; }
  public static function viaB() { return forward_static_call('B::who'); }
}
class B extends A {
  public static function viaB() { return forward_static_call('B::who'); }
  public static function viaParent() { return forward_static_call('parent::who'); }
}
class C extends B {
  public static function viaA() { return forward_static_call('A::who'); }
}
echo A::viaB(), "\n";
echo B::viaB(), "\n";
echo C::viaA(), "\n";
echo B::viaParent(), "\n";
