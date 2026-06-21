--TEST--
Language: private(set) get-only property hook write — asymmetric Error not read-only (#9842, zend_property_hooks.c)
--FILE--
<?php
class C {
    private(set) string $x {
        get => 'hi';
    }
}
$c = new C();
echo $c->x, "\n";
try {
    $c->x = 'no';
    echo "no-error\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
hi
Cannot modify private(set) property C::$x from global scope
