--TEST--
isset()/empty() on write-only virtual property hook throws Error (issue #6484, zend_property_hooks.c)
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
    var_dump(isset($c->x));
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    var_dump(empty($c->x));
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
Error: Cannot read property C::$x without get hook
Error: Cannot read property C::$x without get hook
