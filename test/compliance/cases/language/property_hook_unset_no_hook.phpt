--TEST--
unset() on hooked property without unset hook throws Error (issue #6502, zend_property_hooks.c)
--FILE--
<?php
class C {
    public string $x {
        get => $this->x ?? 'u';
        set => $this->x = $value;
    }
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
