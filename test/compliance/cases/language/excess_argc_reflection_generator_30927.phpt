--TEST--
language: ReflectionGenerator zero-arg methods excess argc → ArgumentCountError (#30927, ext/reflection/php_reflection.c)
--FILE--
<?php
function gen() { yield 1; }
$g = gen();
$g->current();
$r = new ReflectionGenerator($g);
foreach (['getExecutingLine', 'getExecutingFile', 'getFunction', 'getThis'] as $m) {
    try {
        $r->$m('x');
        echo $m, ": OK\n";
    } catch (Throwable $e) {
        echo $m, ': ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}
--EXPECT--
getExecutingLine: ArgumentCountError: ReflectionGenerator::getExecutingLine() expects exactly 0 arguments, 1 given
getExecutingFile: ArgumentCountError: ReflectionGenerator::getExecutingFile() expects exactly 0 arguments, 1 given
getFunction: ArgumentCountError: ReflectionGenerator::getFunction() expects exactly 0 arguments, 1 given
getThis: ArgumentCountError: ReflectionGenerator::getThis() expects exactly 0 arguments, 1 given
