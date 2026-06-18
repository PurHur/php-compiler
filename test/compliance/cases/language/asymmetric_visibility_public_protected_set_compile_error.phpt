--TEST--
Language: public protected(set) compiles and enforces set visibility (#9622, PHP 8.4 zend_compile.c)
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
