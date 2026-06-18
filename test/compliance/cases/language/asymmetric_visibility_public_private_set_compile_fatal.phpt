--TEST--
Language: public private(set) compiles and enforces set visibility (#9622, PHP 8.4 zend_compile.c)
--FILE--
<?php
class C {
    public private(set) int $x = 1;
}
$c = new C();
echo $c->x, "\n";
try {
    $c->x = 2;
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
1
Error: Cannot modify private(set) property C::$x from global scope
