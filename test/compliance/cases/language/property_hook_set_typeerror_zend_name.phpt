--TEST--
Language: set-hook TypeError uses Class::$prop::set() not __phpc_property_set_* (#29666, zend_property_hooks.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class C {
    public int $x {
        set(int $v) { $this->x = $v; }
    }
}
$o = new C();
try {
    $o->x = 'nope';
    echo "SET\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECTF--
C::$x::set(): Argument #1 ($v) must be of type int, string given, called in %s on line %d
