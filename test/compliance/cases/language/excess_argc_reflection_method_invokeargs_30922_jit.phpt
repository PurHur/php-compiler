--TEST--
language: ReflectionMethod::invokeArgs() excess argc → ArgumentCountError JIT (#30922, php_reflection.c)
--FILE--
<?php
class RmInvokeArgsArgcC { function m($a){ return $a; } }
$rm = new ReflectionMethod(RmInvokeArgsArgcC::class, 'm');
$o = new RmInvokeArgsArgcC();
foreach ([
  'hi' => fn() => $rm->invokeArgs($o, [1], 'x'),
  'lo' => fn() => $rm->invokeArgs($o),
  'ok' => fn() => $rm->invokeArgs($o, [1]),
] as $label => $fn) {
  try {
    $v = $fn();
    echo "$label ACCEPTED:", var_export($v, true), "\n";
  } catch (Throwable $e) {
    echo "$label ", get_class($e), ': ', $e->getMessage(), "\n";
  }
}
--EXPECT--
hi ArgumentCountError: ReflectionMethod::invokeArgs() expects exactly 2 arguments, 3 given
lo ArgumentCountError: ReflectionMethod::invokeArgs() expects exactly 2 arguments, 1 given
ok ACCEPTED:1
