--TEST--
unset() on write-only virtual property hook throws Error (issue #6491, zend_property_hooks.c)
--FILE--
<?php
class C {
    public string $x {
        set => $this->backing = strtoupper($value);
    }
    private string $backing = '';
}
$c = new C();
$c->x = 'hi';
try {
    unset($c->x);
    echo "unset ok\n";
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
Error: Cannot unset hooked property C::$x
