--TEST--
unset() on get-only virtual property hook throws Error (issue #6425, zend_property_hooks.c)
--FILE--
<?php
class RO {
    public string $x {
        get => $this->v;
    }
    private string $v = 'a';
}
$h = new RO();
try {
    unset($h->x);
    echo "ok\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
Cannot unset hooked property RO::$x
