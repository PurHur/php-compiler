--TEST--
Language: public protected(set) — read public, write protected (#15368, Zend/zend_compile.c)
--FILE--
<?php
class A {
    public protected(set) string $x = 'ok';
}
$a = new A();
echo $a->x, "\n";
try {
    $a->x = 'nope';
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
ok
Error: Cannot modify protected(set) property A::$x from global scope
