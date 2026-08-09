--TEST--
Language: backed &get + set rejected JIT (zend_verify_hooked_property, #29230)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class H {
    private array $b = [1, 2];
    public array $a {
        &get => $this->b;
        set => $this->b = $value;
    }
}
$h = new H;
echo "ok\n";
--EXPECT_EXIT--
255
--EXPECTF--
PHP Fatal error:  Get hook of backed property H::a with set hook may not return by reference in %s on line %d
