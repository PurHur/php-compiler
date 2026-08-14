--TEST--
Nullable TypeError expected type prints ?T not T|null (#29960, zend_execute_API.c)
--FILE--
<?php
foreach ([
  'param_int' => function () {
    $f = function (?int $x) {};
    $f('a');
  },
  'param_string' => function () {
    $f = function (?string $x) {};
    $f([]);
  },
  'param_false' => function () {
    $f = function (?false $x) {};
    $f(true);
  },
  'param_array' => function () {
    $f = function (?array $x) {};
    $f(1);
  },
  'param_bool' => function () {
    $f = function (?bool $x) {};
    $f([]);
  },
  'param_float' => function () {
    $f = function (?float $x) {};
    $f([]);
  },
  'param_object' => function () {
    $f = function (?object $x) {};
    $f(1);
  },
  'param_true' => function () {
    $f = function (?true $x) {};
    $f(false);
  },
  'return_int' => function () {
    $f = function (): ?int { return 'a'; };
    $f();
  },
  'return_false' => function () {
    $f = function (): ?false { return true; };
    $f();
  },
  'return_string' => function () {
    $f = function (): ?string { return []; };
    $f();
  },
  'union_keeps_pipe' => function () {
    $f = function (int|string|null $x) {};
    $f([]);
  },
] as $name => $fn) {
  try {
    $fn();
    echo $name, ": no error\n";
  } catch (Throwable $e) {
    // Strip call-site suffix so VM/JIT line numbers stay portable.
    $msg = $e->getMessage();
    $msg = preg_replace('/, called in .*$/', '', $msg);
    echo $name, ':', $msg, "\n";
  }
}
?>
--EXPECT--
param_int:{closure}(): Argument #1 ($x) must be of type ?int, string given
param_string:{closure}(): Argument #1 ($x) must be of type ?string, array given
param_false:{closure}(): Argument #1 ($x) must be of type ?false, bool given
param_array:{closure}(): Argument #1 ($x) must be of type ?array, int given
param_bool:{closure}(): Argument #1 ($x) must be of type ?bool, array given
param_float:{closure}(): Argument #1 ($x) must be of type ?float, array given
param_object:{closure}(): Argument #1 ($x) must be of type ?object, int given
param_true:{closure}(): Argument #1 ($x) must be of type ?true, bool given
return_int:{closure}(): Return value must be of type ?int, string returned
return_false:{closure}(): Return value must be of type ?false, bool returned
return_string:{closure}(): Return value must be of type ?string, array returned
union_keeps_pipe:{closure}(): Argument #1 ($x) must be of type string|int|null, array given
