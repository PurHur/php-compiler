--TEST--
Language: asymmetric visibility — explicit read before set modifier (#9622, PHP 8.4 zend_compile.c)
--FILE--
<?php
class A {
    public private(set) string $x = 'a';
}
$a = new A();
echo $a->x, "\n";
try {
    $a->x = 'b';
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
a
Error: Cannot modify private(set) property A::$x from global scope
