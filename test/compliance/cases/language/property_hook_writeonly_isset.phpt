--TEST--
isset()/empty() on write-only virtual property hook throws Error (issue #6484, #22452, zend_property_hooks.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
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
Error: Property C::$x is write-only
Error: Property C::$x is write-only
