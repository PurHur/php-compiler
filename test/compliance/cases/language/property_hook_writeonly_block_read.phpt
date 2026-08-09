--TEST--
Write-only block-syntax property hook rejects reads via separate backing (#6635, zend_property_hooks.c)
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
    echo $c->x, "\n";
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
Error: Property C::$x is write-only
