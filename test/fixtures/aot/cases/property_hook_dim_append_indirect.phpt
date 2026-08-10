--TEST--
AOT: hooked prop []= / [$k]= without &get → Indirect modification (#29748, zend_property_hooks.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class C {
    public array $prop {
        get { echo "GET\n"; return $this->prop ??= []; }
        set { echo "SET\n"; $this->prop = $value; }
    }
}
$o = new C();
try {
    $o->prop[] = 1;
    echo "ok\n";
} catch (Throwable $e) {
    echo get_class($e), ": ", $e->getMessage(), "\n";
}
try {
    $o->prop['k'] = 2;
    echo "ok2\n";
} catch (Throwable $e) {
    echo get_class($e), ": ", $e->getMessage(), "\n";
}
--EXPECT--
GET
Error: Indirect modification of C::$prop is not allowed
GET
Error: Indirect modification of C::$prop is not allowed
