--TEST--
Language: property hooks with asymmetric set visibility (#6898, zend_property_hooks.c)
--FILE--
<?php
class C {
    public string $x {
        get => 'g';
        set (protected) => $value;
    }
}
$c = new C();
echo $c->x, "\n";
try {
    $c->x = 'n';
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
echo $c->x, "\n";
--EXPECT--
g
Error: Cannot modify protected(set) property C::$x from global scope
g
