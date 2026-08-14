--TEST--
language: ReflectionClass newInstanceArgs/newInstanceWithoutConstructor excess argc → ArgumentCountError (#30923, php_reflection.c)
--FILE--
<?php
class RcNewInstanceArgcC { public $x; function __construct($a=0){ $this->x=$a; } }
$rc = new ReflectionClass(RcNewInstanceArgcC::class);
foreach ([
  'args_hi' => fn() => $rc->newInstanceArgs([1], 'x')->x,
  'niwc_hi' => function() use ($rc) { $rc->newInstanceWithoutConstructor(1); return 'ok'; },
  'args_ok' => fn() => $rc->newInstanceArgs([1])->x,
  'args_omit' => fn() => $rc->newInstanceArgs()->x,
  'niwc_ok' => function() use ($rc) { $rc->newInstanceWithoutConstructor(); return 'ok'; },
] as $label => $fn) {
  try {
    $v = $fn();
    echo "$label ACCEPTED:", var_export($v, true), "\n";
  } catch (Throwable $e) {
    echo "$label ", get_class($e), ': ', $e->getMessage(), "\n";
  }
}
--EXPECT--
args_hi ArgumentCountError: ReflectionClass::newInstanceArgs() expects at most 1 argument, 2 given
niwc_hi ArgumentCountError: ReflectionClass::newInstanceWithoutConstructor() expects exactly 0 arguments, 1 given
args_ok ACCEPTED:1
args_omit ACCEPTED:0
niwc_ok ACCEPTED:'ok'
