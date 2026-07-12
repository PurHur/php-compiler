<?php
// Issue #18170 — PHP 8.4 explicit `virtual` modifier on property hooks.

class Base {
    public virtual string $x {
        get => 'base';
    }
}

class Child extends Base {
    public virtual string $x {
        get => parent::$x->get() . '-child';
    }
}

$b = new Base();
echo $b->x, "\n";

$c = new Child();
echo $c->x, "\n";

echo "PASS_VIRTUAL_PROPERTY_HOOKS\n";
