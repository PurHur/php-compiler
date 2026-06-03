--TEST--
Language: uninitialized inherited typed property — JIT/AOT (#4614)
--SKIPIF--
<?php
if (!getenv('PHP_COMPILER_JIT_EXECUTE')) {
    die('skip JIT execute not enabled');
}
--FILE--
<?php
class Base { public int $x; }
class Child extends Base {}

$c = new Child();
var_export(isset($c->x));
echo "\n";
try {
    echo $c->x;
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
false
Typed property Child::$x must not be accessed before initialization
