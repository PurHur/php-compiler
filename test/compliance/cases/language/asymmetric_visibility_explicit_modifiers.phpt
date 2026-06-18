--TEST--
Language: asymmetric visibility — explicit public private(set) enforces set visibility (#9326, zend_compile.c PHP 8.4)
--FILE--
<?php
class A {
    public private(set) string $x = 'a';
}
$a = new A();
echo $a->x, "\n";
try {
    $a->x = 'no';
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
a
Error: Cannot modify private(set) property A::$x from global scope
