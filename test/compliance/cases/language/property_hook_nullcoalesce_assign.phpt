--TEST--
Property hook ??= — null-check backing, assign via set hook, read via get hook (#6472, zend_property_hooks.c)
--FILE--
<?php
class C {
    private ?string $x = null;
    public string $y {
        get => $this->x ?? 'default';
        set => $this->x = $value;
    }
}
$c = new C();
$c->y ??= 'assigned';
echo $c->y, "\n";

class D {
    private string $x = 'kept';
    public string $y {
        get => $this->x;
        set => $this->x = $value;
    }
}
$d = new D();
$d->y ??= 'ignored';
echo $d->y, "\n";
--EXPECT--
assigned
kept
