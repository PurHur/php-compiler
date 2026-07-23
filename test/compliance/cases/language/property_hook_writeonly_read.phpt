--TEST--
Write-only virtual property hook rejects reads (issue #6484, #22452, zend_property_hooks.c)
--FILE--
<?php
class C {
    public int $x {
        set { $this->v = $value; }
        private int $v = 0;
    }
}
$c = new C();
$c->x = 5;
try {
    echo $c->x, "\n";
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
Error: Cannot read property C::$x without get hook
