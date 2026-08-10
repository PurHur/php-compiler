--TEST--
Property hook set parameter type enforced at invoke (#7301, #29666, zend_property_hooks.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);
class C {
    public string $x {
        get => $this->v ?? 'u';
        set(string $value) { $this->v = $value; }
    }
    private ?string $v = 'a';
}
$c = new C();
try {
    $c->x = 1;
    echo "no error\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECTF--
C::$x::set(): Argument #1 ($value) must be of type string, int given, called in %s on line %d
