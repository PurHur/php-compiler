--TEST--
Language: method call on scalar/null throws catchable Error (#15529, Zend/zend_execute.c)
--FILE--
<?php
try {
    (1)->m();
} catch (Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
}
try {
    (true)->x();
} catch (Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
}
try {
    null->foo();
} catch (Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
}
--EXPECT--
Error: Call to a member function m() on int
Error: Call to a member function x() on true
Error: Call to a member function foo() on null
