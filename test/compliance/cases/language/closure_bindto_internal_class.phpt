--TEST--
language: Closure::bindTo() internal class scope warns; null invoke is catchable Error (#5170, zend_closures.c)
--FILE--
<?php
$c = function () { return 42; };
$b = $c->bindTo(new stdClass(), 'stdClass');
try {
    $b();
} catch (Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage();
}
--EXPECTF--
PHP Warning:  Cannot bind closure to scope of internal class stdClass in %s on line %d
Error: Value of type null is not callable
