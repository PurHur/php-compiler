--TEST--
Write-only virtual property hook rejects reads (issue #6484, zend_property_hooks.c)
--FILE--
<?php
class C {
    public string $x {
        set => $this->x = strtoupper($value);
    }
}
$c = new C();
$c->x = 'hi';
try {
    echo $c->x, "\n";
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
Error: Cannot read property C::$x without get hook
