--TEST--
language: Closure::bindTo($internalInstance) succeeds when scope omitted (#12562, zend_closures.c)
--FILE--
<?php
$c = function (): int { return 42; };
$std = $c->bindTo(new stdClass());
$ao = $c->bindTo(new ArrayObject());
echo ($std instanceof Closure) ? 'std ok' : 'std fail';
echo "\n";
echo ($ao instanceof Closure) ? 'ao ok' : 'ao fail';
echo "\n";
echo $std();
--EXPECT--
std ok
ao ok
42
