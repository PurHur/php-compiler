<?php
class A {
  public static function who($x, $y = '') { return static::class . ':' . $x . ':' . $y; }
}
class B extends A {}
echo call_user_func_array([A::class, 'who'], ['z', 'w']), "\n";
echo call_user_func_array([B::class, 'who'], ['z', 'w']), "\n";
echo call_user_func([A::class, 'who'], 'z', 'w'), "\n";
echo call_user_func_array('sprintf', ['%s-%s', 'a', 'b']), "\n";
echo forward_static_call_array([B::class, 'who'], ['z']), "\n";
