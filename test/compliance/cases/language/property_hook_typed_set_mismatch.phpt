--TEST--
Property hook set parameter type enforced at invoke (#7301, zend_property_hooks.c)
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
    echo "TypeError\n";
}
--EXPECT--
TypeError
