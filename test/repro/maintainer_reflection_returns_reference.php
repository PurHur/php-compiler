<?php
function &byref() { static $x = 1; return $x; }
function byval() { return 1; }
class T {
  public function &mref() { static $x = 1; return $x; }
  public function mval() { return 1; }
}
foreach ([
  new ReflectionFunction('byref'),
  new ReflectionFunction('byval'),
  new ReflectionMethod(T::class, 'mref'),
  new ReflectionMethod(T::class, 'mval'),
  new ReflectionFunction('strlen'),
] as $r) {
  echo method_exists($r, 'returnsReference') ? var_export($r->returnsReference(), true) : 'MISSING';
  echo "\n";
}
